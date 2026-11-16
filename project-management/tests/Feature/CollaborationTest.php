<?php

use App\Collaboration\Models\Invitation;
use App\Collaboration\Models\Participant;
use App\Collaboration\Models\Representative;
use App\Models\AuditEvent;
use App\Models\OrgUnit;
use App\Models\Person;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Organizations taking part in each other's projects (ADR-0014). The project
| stays its owner's; others join only when someone who manages their
| collaboration accepts, see it in their own workspace through a reviewed
| projection, and can do no more than they were explicitly given, by someone
| who could give it.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 09:00'));
    Notification::fake();
});

it('runs a project of its own with nobody else taking part', function () {
    $project = collabProject();

    $this->get($this->organizationUrl('acme', "projects/{$project->id}/collaboration"))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal/projects/collaboration')
        ->has('participants', 0)
        ->has('invitations', 0)
        ->where('canManage', true));
    expect(Participant::query()->count())->toBe(0);
});

it('gives an invited organization nothing until someone who manages it accepts', function () {
    $project = collabProject();
    $invitation = inviteToProject($project, 'water-now');

    expect($invitation->status())->toBe(Invitation::PENDING)
        ->and(Participant::query()->count())->toBe(0);

    // A member of the invited organization who does not manage its collaboration sees nothing to answer.
    $this->actingAsMember('water-now', ['projects.view']);
    $this->get($this->organizationUrl('water-now', 'shared-projects'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('invitations', 0)->has('projects', 0));
    $this->post($this->organizationUrl('water-now', "shared-projects/invitations/{$invitation->id}/accept"))->assertForbidden();

    // Someone who does sees it, with who asks and why.
    $this->actingAsMember('water-now', COLLAB_MANAGER);
    $this->get($this->organizationUrl('water-now', 'shared-projects'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal/shared-projects/index')
        ->where('invitations.0.project', 'Clean water')
        ->where('invitations.0.from', 'Acme')
        ->where('invitations.0.message', 'Join us on the water project.')
        ->has('projects', 0));
});

it('lets a donor invite an implementer, and an implementer invite a donor', function () {
    // The donor owns this one and brings in who implements it.
    $donorProject = collabProject('global-fund', 'GF1');
    $invitation = inviteToProject($donorProject, 'water-now', owner: 'global-fund');
    $implementer = acceptProjectInvitation($invitation, 'water-now');

    expect($implementer->status)->toBe(Participant::ACTIVE)
        ->and($implementer->organization_id)->toBe($this->organization('global-fund')->id)
        ->and($implementer->participant_organization_id)->toBe($this->organization('water-now')->id);

    // An implementer owns this one and brings in its donor: ownership never moves.
    $ownProject = collabProject('acme', 'ACM');
    $invitation = inviteToProject($ownProject, 'global-fund', 'donor', ['view', 'budget.view']);
    $donor = acceptProjectInvitation($invitation, 'global-fund');

    expect($donor->relationship)->toBe('donor')
        ->and($this->asTenant('acme', fn () => Project::query()->findOrFail($ownProject->id)->organization_id))->toBe($this->organization('acme')->id);
});

it('shows a participant the project in its own workspace through the projection only', function () {
    $project = collabProject();
    $participant = acceptProjectInvitation(inviteToProject($project, 'water-now'), 'water-now');

    $response = $this->get($this->organizationUrl('water-now', "shared-projects/{$participant->id}"))->assertOk();
    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store');
    $response->assertInertia(fn (Assert $page) => $page
        ->component('portal/shared-projects/show')
        ->where('project.name', 'Clean water')
        ->where('project.owner', 'Acme')
        ->where('project.milestones.0.name', 'Wells drilled')
        // Not given budget.view: no budget, whatever the project holds.
        ->where('project.budget', null)
        ->missing('project.tasks')
        ->where('participation.relationship', 'implementer')
        ->has('participation.representatives', 1));

    // The one who accepted works on it; the owner's side sees who takes part.
    expect(Representative::query()->where('participant_id', $participant->id)->count())->toBe(1);
    $this->actingAsMember('acme', [], owner: true);
    $this->get($this->organizationUrl('acme', "projects/{$project->id}/collaboration"))->assertInertia(fn (Assert $page) => $page
        ->where('participants.0.organization', 'Water Now')
        ->where('participants.0.status', 'active')
        ->where('invitations.0.status', 'accepted'));
});

it('keeps a shared project from other organizations and from members not on it', function () {
    $project = collabProject();
    $participant = acceptProjectInvitation(inviteToProject($project, 'water-now'), 'water-now');

    // Another organization: unknown.
    $this->actingAsMember('other-org', [], owner: true);
    $this->get($this->organizationUrl('other-org', "shared-projects/{$participant->id}"))->assertNotFound();

    // A member of the participant who does not work on it: unknown too.
    $this->actingAsMember('water-now', ['projects.view']);
    $this->get($this->organizationUrl('water-now', "shared-projects/{$participant->id}"))->assertNotFound();
    $this->get($this->organizationUrl('water-now', 'shared-projects'))->assertInertia(fn (Assert $page) => $page->has('projects', 0));

    // Participation gives no place in the owner's workspace.
    $this->get($this->organizationUrl('acme', "projects/{$project->id}"))->assertForbidden();
});

it('lets a participant choose who works on the project for it', function () {
    $project = collabProject();
    $participant = acceptProjectInvitation(inviteToProject($project, 'water-now'), 'water-now');
    $colleague = $this->member('water-now', ['projects.view']);
    $outsider = $this->member('other-org', ['projects.view']);

    $this->actingAsMember('water-now', COLLAB_MANAGER);
    $this->post($this->organizationUrl('water-now', "shared-projects/{$participant->id}/representatives"), ['user_id' => $outsider->id])->assertSessionHasErrors('user_id');
    $this->post($this->organizationUrl('water-now', "shared-projects/{$participant->id}/representatives"), ['user_id' => $colleague->id, 'responsibilities' => ['mel', 'field']])->assertSessionHasNoErrors();

    $this->signInTo('water-now', $colleague);
    $this->get($this->organizationUrl('water-now', "shared-projects/{$participant->id}"))->assertOk();
    $this->get($this->organizationUrl('water-now', 'shared-projects'))->assertInertia(fn (Assert $page) => $page->has('projects', 1));
    // Working on it is not managing it.
    $this->post($this->organizationUrl('water-now', "shared-projects/{$participant->id}/representatives"), ['user_id' => $colleague->id])->assertForbidden();

    $this->actingAsMember('water-now', COLLAB_MANAGER);
    $representative = Representative::query()->where('user_id', $colleague->id)->firstOrFail();
    $this->delete($this->organizationUrl('water-now', "shared-projects/{$participant->id}/representatives/{$representative->id}"))->assertSessionHasNoErrors();

    $this->signInTo('water-now', $colleague);
    $this->get($this->organizationUrl('water-now', "shared-projects/{$participant->id}"))->assertNotFound();
    expect($representative->fresh()?->removed_at)->not->toBeNull();
});

it('refuses to invite without the permission, or to give what the inviter could not do', function () {
    $project = collabProject();

    $this->actingAsMember('acme', ['projects.view', 'projects.manage']);
    $this->post($this->organizationUrl('acme', "projects/{$project->id}/collaboration/invitations"), [
        'organization_id' => $this->organization('water-now')->id, 'relationship' => 'implementer', 'capabilities' => ['view'],
    ])->assertForbidden();

    // May invite, but cannot see the project's money: cannot give budget.view.
    $this->actingAsMember('acme', COLLAB_MANAGER);
    $this->post($this->organizationUrl('acme', "projects/{$project->id}/collaboration/invitations"), [
        'organization_id' => $this->organization('water-now')->id, 'relationship' => 'donor', 'capabilities' => ['view', 'budget.view'],
    ])->assertSessionHasErrors('capabilities');
    expect(Invitation::query()->count())->toBe(0);

    $this->post($this->organizationUrl('acme', "projects/{$project->id}/collaboration/invitations"), [
        'organization_id' => $this->organization('water-now')->id, 'relationship' => 'donor', 'capabilities' => ['view'],
    ])->assertSessionHasNoErrors();
    expect(Invitation::query()->sole()->capabilities)->toBe(['view']);
});

it('refuses a second invitation, and an organization already taking part', function () {
    $project = collabProject();
    $invitation = inviteToProject($project, 'water-now');

    $this->post($this->organizationUrl('acme', "projects/{$project->id}/collaboration/invitations"), [
        'organization_id' => $this->organization('water-now')->id, 'relationship' => 'implementer', 'capabilities' => ['view'],
    ])->assertSessionHasErrors('organization_id');
    $this->post($this->organizationUrl('acme', "projects/{$project->id}/collaboration/invitations"), [
        'organization_id' => $this->organization('acme')->id, 'relationship' => 'implementer', 'capabilities' => ['view'],
    ])->assertSessionHasErrors('organization_id');

    acceptProjectInvitation($invitation, 'water-now');
    $this->actingAsMember('acme', [], owner: true);
    $this->post($this->organizationUrl('acme', "projects/{$project->id}/collaboration/invitations"), [
        'organization_id' => $this->organization('water-now')->id, 'relationship' => 'donor', 'capabilities' => ['view'],
    ])->assertSessionHasErrors('organization_id');
});

it('checks the inviter again when the invitation is accepted', function () {
    $project = collabProject();
    $inviter = $this->actingAsMember('acme', [...COLLAB_MANAGER, 'projects.finance.view']);
    $this->post($this->organizationUrl('acme', "projects/{$project->id}/collaboration/invitations"), [
        'organization_id' => $this->organization('water-now')->id, 'relationship' => 'donor', 'capabilities' => ['view', 'budget.view'],
    ])->assertSessionHasNoErrors();
    $invitation = Invitation::query()->sole();

    // Their roles change before it is answered: they could no longer give budget.view.
    $this->member('acme', COLLAB_MANAGER, user: $inviter);

    $this->actingAsMember('water-now', COLLAB_MANAGER);
    $this->post($this->organizationUrl('water-now', "shared-projects/invitations/{$invitation->id}/accept"))->assertSessionHasErrors('invitation');
    expect(Participant::query()->count())->toBe(0);
});

it('lets a lead implementer bring in sub-partners within what it holds', function () {
    $project = collabProject();
    $lead = acceptProjectInvitation(inviteToProject($project, 'lead-ngo', 'lead_implementer', ['view', 'partners.manage', 'reports.submit']), 'lead-ngo');
    $donor = acceptProjectInvitation(inviteToProject($project, 'global-fund', 'donor', ['view', 'budget.view']), 'global-fund');

    $this->actingAsMember('lead-ngo', COLLAB_MANAGER);
    // Not more than it holds.
    $this->post($this->organizationUrl('lead-ngo', "shared-projects/{$lead->id}/invitations"), [
        'organization_id' => $this->organization('field-ngo')->id, 'relationship' => 'sub_implementer', 'capabilities' => ['view', 'budget.view'],
    ])->assertSessionHasErrors('capabilities');
    $this->post($this->organizationUrl('lead-ngo', "shared-projects/{$lead->id}/invitations"), [
        'organization_id' => $this->organization('field-ngo')->id, 'relationship' => 'sub_implementer', 'capabilities' => ['view', 'reports.submit'],
    ])->assertSessionHasNoErrors();

    $invitation = Invitation::query()->where('invited_organization_id', $this->organization('field-ngo')->id)->sole();
    expect($invitation->parent_participant_id)->toBe($lead->id)
        ->and($invitation->inviting_organization_id)->toBe($this->organization('lead-ngo')->id);
    $sub = acceptProjectInvitation($invitation, 'field-ngo');

    // Three organizations besides the owner; the sub-partner sees its own chain, not the donor.
    expect(Participant::query()->where('project_id', $project->id)->where('status', 'active')->count())->toBe(3);
    $this->get($this->organizationUrl('field-ngo', "shared-projects/{$sub->id}"))->assertInertia(fn (Assert $page) => $page
        ->has('participants', 2)
        ->where('participants', fn ($participants) => collect($participants)->pluck('organization')->sort()->values()->all() === ['Field Ngo', 'Lead Ngo']));
    // One at the top sees everyone.
    $this->actingAsMember('global-fund', COLLAB_MANAGER);
    $this->get($this->organizationUrl('global-fund', "shared-projects/{$donor->id}"))->assertInertia(fn (Assert $page) => $page->has('participants', 3));

    // A participant without partners.manage cannot invite.
    $this->post($this->organizationUrl('global-fund', "shared-projects/{$donor->id}/invitations"), [
        'organization_id' => $this->organization('other-org')->id, 'relationship' => 'implementer', 'capabilities' => ['view'],
    ])->assertForbidden();
});

it('cuts off a revoked participant, and those under a suspended one, on the next request', function () {
    $project = collabProject();
    $lead = acceptProjectInvitation(inviteToProject($project, 'lead-ngo', 'lead_implementer', ['view', 'partners.manage']), 'lead-ngo');
    $this->actingAsMember('lead-ngo', COLLAB_MANAGER);
    $this->post($this->organizationUrl('lead-ngo', "shared-projects/{$lead->id}/invitations"), [
        'organization_id' => $this->organization('field-ngo')->id, 'relationship' => 'sub_implementer', 'capabilities' => ['view'],
    ])->assertSessionHasNoErrors();
    $sub = acceptProjectInvitation(Invitation::query()->where('invited_organization_id', $this->organization('field-ngo')->id)->sole(), 'field-ngo');

    $this->get($this->organizationUrl('field-ngo', "shared-projects/{$sub->id}"))->assertInertia(fn (Assert $page) => $page->where('project.name', 'Clean water'));

    // The owner suspends the lead: the sub-partner sees nothing of the project.
    $this->actingAsMember('acme', [], owner: true);
    $this->post($this->organizationUrl('acme', "projects/{$project->id}/collaboration/participants/{$lead->id}/status"), ['status' => 'suspended'])->assertSessionHasNoErrors();
    $this->actingAsMember('field-ngo', COLLAB_MANAGER);
    $this->get($this->organizationUrl('field-ngo', "shared-projects/{$sub->id}"))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('project', null)->has('participants', 0)->where('participation.cutOff', true));

    // Brought back, then revoked: gone for good, and kept as history.
    $this->actingAsMember('acme', [], owner: true);
    $this->post($this->organizationUrl('acme', "projects/{$project->id}/collaboration/participants/{$lead->id}/status"), ['status' => 'active'])->assertSessionHasNoErrors();
    $this->post($this->organizationUrl('acme', "projects/{$project->id}/collaboration/participants/{$lead->id}/status"), ['status' => 'revoked'])->assertSessionHasNoErrors();
    $this->post($this->organizationUrl('acme', "projects/{$project->id}/collaboration/participants/{$lead->id}/status"), ['status' => 'active'])->assertStatus(409);

    $this->actingAsMember('lead-ngo', ['projects.view']);
    $this->get($this->organizationUrl('lead-ngo', "shared-projects/{$lead->id}"))->assertNotFound();
    $this->actingAsMember('lead-ngo', COLLAB_MANAGER);
    $this->get($this->organizationUrl('lead-ngo', "shared-projects/{$lead->id}"))->assertInertia(fn (Assert $page) => $page->where('project', null)->where('participation.status', 'revoked'));
    expect(Participant::query()->find($lead->id)?->status)->toBe('revoked');

    // It can be invited again: a new participation, the old one stays.
    $this->actingAsMember('acme', [], owner: true);
    acceptProjectInvitation(inviteToProject($project, 'lead-ngo'), 'lead-ngo');
    expect(Participant::query()->where('participant_organization_id', $this->organization('lead-ngo')->id)->count())->toBe(2);
});

it('changes what a participant may do within the changer\'s authority', function () {
    $project = collabProject();
    $participant = acceptProjectInvitation(inviteToProject($project, 'global-fund', 'donor'), 'global-fund');

    $this->actingAsMember('acme', COLLAB_MANAGER);
    $this->put($this->organizationUrl('acme', "projects/{$project->id}/collaboration/participants/{$participant->id}"), ['capabilities' => ['view', 'budget.view']])->assertSessionHasErrors('capabilities');

    $this->actingAsMember('acme', [], owner: true);
    $this->put($this->organizationUrl('acme', "projects/{$project->id}/collaboration/participants/{$participant->id}"), ['capabilities' => ['budget.view']])->assertSessionHasNoErrors();
    // View is always there.
    expect($participant->fresh()?->capabilities)->toBe(['view', 'budget.view']);

    $this->actingAsMember('global-fund', COLLAB_MANAGER);
    $this->get($this->organizationUrl('global-fund', "shared-projects/{$participant->id}"))->assertInertia(fn (Assert $page) => $page
        // Nothing approved yet: nothing shown.
        ->where('project.budget', null)
        ->where('capabilityLabels', ['View project', 'View approved budgets']));

    expect(AuditEvent::query()->where('action', 'collaboration.capabilities_changed')->count())->toBe(2);
});

it('invites by email someone whose organization is not here yet', function () {
    $project = collabProject();
    $this->post($this->organizationUrl('acme', "projects/{$project->id}/collaboration/invitations"), [
        'email' => 'Director@Hope.example', 'contact_name' => 'Amina Lado', 'relationship' => 'implementer', 'capabilities' => ['view'],
    ])->assertSessionHasNoErrors();
    $link = projectInvitationLink('director@hope.example');
    $token = basename((string) parse_url($link, PHP_URL_PATH));
    expect($link)->toContain('acme.')->and(Invitation::query()->sole()->token_hash)->not->toBe($token);

    // Opened on the inviter's workspace by someone who is not a member there.
    auth()->logout();
    $this->get($link)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal/collaboration/landing')
        ->where('invitation.project', 'Clean water'));
    $this->post($link, ['workspace' => 'not a name'])->assertSessionHasErrors('workspace');
    $this->post($link, ['workspace' => 'Hope-NGO'])->assertRedirect($this->organizationUrl('hope-ngo', "project-invitations/{$token}/open"));

    // In their own workspace, once it exists, someone who manages it accepts.
    $this->actingAsMember('hope-ngo', COLLAB_MANAGER);
    $this->get($this->organizationUrl('hope-ngo', "project-invitations/{$token}/open"))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal/collaboration/show')->where('acceptable', true));
    // Never by its id.
    $this->post($this->organizationUrl('hope-ngo', 'shared-projects/invitations/'.Invitation::query()->sole()->id.'/accept'))->assertNotFound();
    $this->post($this->organizationUrl('hope-ngo', "project-invitations/{$token}/accept"))->assertSessionHasNoErrors()->assertRedirect();

    expect(Participant::query()->sole()->participant_organization_id)->toBe($this->organization('hope-ngo')->id);
    // The link works once.
    $this->post($this->organizationUrl('hope-ngo', "project-invitations/{$token}/accept"))->assertNotFound();
});

it('lets the inviter withdraw an invitation, or send it again with a new link', function () {
    $project = collabProject();
    $this->post($this->organizationUrl('acme', "projects/{$project->id}/collaboration/invitations"), [
        'email' => 'director@hope.example', 'contact_name' => 'Amina Lado', 'relationship' => 'implementer', 'capabilities' => ['view'],
    ])->assertSessionHasNoErrors();
    $first = basename((string) parse_url(projectInvitationLink('director@hope.example'), PHP_URL_PATH));
    $invitation = Invitation::query()->sole();

    // Expired: sent again with a new link; the old one stops working.
    $this->travel((int) config('access.invitation_days') + 1)->days();
    expect($invitation->fresh()?->status())->toBe(Invitation::EXPIRED);
    Notification::fake();
    $this->post($this->organizationUrl('acme', "projects/{$project->id}/collaboration/invitations/{$invitation->id}/resend"))->assertSessionHasNoErrors();
    $second = basename((string) parse_url(projectInvitationLink('director@hope.example'), PHP_URL_PATH));
    expect($second)->not->toBe($first)->and(Invitation::findOpen($first))->toBeNull()->and(Invitation::findOpen($second))->not->toBeNull();

    $this->post($this->organizationUrl('acme', "projects/{$project->id}/collaboration/invitations/{$invitation->id}/revoke"))->assertSessionHasNoErrors();
    expect(Invitation::findOpen($second))->toBeNull()->and($invitation->fresh()?->status())->toBe(Invitation::REVOKED);

    $this->actingAsMember('hope-ngo', COLLAB_MANAGER);
    $this->post($this->organizationUrl('hope-ngo', "project-invitations/{$second}/accept"))->assertNotFound();
    expect(Participant::query()->count())->toBe(0);
});

it('lets the invited organization decline', function () {
    $project = collabProject();
    $invitation = inviteToProject($project, 'water-now');

    $this->actingAsMember('water-now', COLLAB_MANAGER);
    $this->post($this->organizationUrl('water-now', "shared-projects/invitations/{$invitation->id}/decline"))->assertSessionHasNoErrors();
    $this->post($this->organizationUrl('water-now', "shared-projects/invitations/{$invitation->id}/accept"))->assertStatus(409);

    expect($invitation->fresh()?->status())->toBe(Invitation::DECLINED)
        ->and(AuditEvent::query()->where('action', 'collaboration.declined')->where('organization_id', $this->organization('acme')->id)->exists())->toBeTrue();
});

it('refuses another organization acting on an invitation that is not its own', function () {
    $project = collabProject();
    $invitation = inviteToProject($project, 'water-now');

    $this->actingAsMember('other-org', [], owner: true);
    $this->post($this->organizationUrl('other-org', "shared-projects/invitations/{$invitation->id}/accept"))->assertNotFound();
    $this->post($this->organizationUrl('other-org', "projects/{$project->id}/collaboration/invitations/{$invitation->id}/revoke"))->assertNotFound();
    expect($invitation->fresh()?->status())->toBe(Invitation::PENDING);
});

it('finds organizations to invite by name unless they opted out, or by exact address, never all of them', function () {
    collabProject();
    $this->organization('water-now');
    // Turned off "Finding you" in Settings: found by its exact address only.
    $this->organization('quiet-ngo')->forceFill(['discoverable' => false])->save();

    $this->getJson($this->organizationUrl('acme', 'collaboration/organizations?q=quiet'))->assertOk()->assertJsonCount(0);
    $this->getJson($this->organizationUrl('acme', 'collaboration/organizations?q=quiet-ngo'))->assertOk()->assertJsonPath('0.name', 'Quiet Ngo');

    $this->actingAsMember('acme', ['projects.view']);
    $this->getJson($this->organizationUrl('acme', 'collaboration/organizations?q=quiet-ngo'))->assertForbidden();
});

it('gives project team members responsibilities and, optionally, a department', function () {
    $project = collabProject();
    $person = $this->asTenant('acme', fn () => Person::query()->create(['name' => 'Achol Garang']));
    $unit = $this->asTenant('acme', fn () => OrgUnit::query()->create(['kind' => 'department', 'name' => 'Programmes']));

    $this->post($this->organizationUrl('acme', "projects/{$project->id}/members"), [
        'person_id' => $person->id, 'allocation_percent' => 50, 'responsibilities' => ['procurement', 'nonsense'],
    ])->assertSessionHasErrors('responsibilities.1');
    $this->post($this->organizationUrl('acme', "projects/{$project->id}/members"), [
        'person_id' => $person->id, 'allocation_percent' => 50, 'responsibilities' => ['procurement'],
    ])->assertSessionHasNoErrors();
    $member = $this->asTenant('acme', fn () => $project->members()->where('person_id', $person->id)->firstOrFail());

    $this->put($this->organizationUrl('acme', "projects/{$project->id}/members/{$member->id}"), [
        'allocation_percent' => 40, 'responsibilities' => ['mel', 'department_representative'], 'org_unit_id' => $unit->id,
    ])->assertSessionHasNoErrors();

    $this->get($this->organizationUrl('acme', "projects/{$project->id}/team"))->assertInertia(fn (Assert $page) => $page
        ->where('members', fn ($members) => collect($members)->contains(fn ($row) => $row['name'] === 'Achol Garang'
            && $row['responsibilities'] === ['department_representative', 'mel'] && $row['unit'] === 'Programmes' && $row['allocation'] === 40))
        ->where('units.0.label', 'Programmes'));

    // Another organization's department is not one of ours.
    $theirs = $this->asTenant('other-org', fn () => OrgUnit::query()->create(['kind' => 'department', 'name' => 'Theirs']));
    $this->put($this->organizationUrl('acme', "projects/{$project->id}/members/{$member->id}"), [
        'allocation_percent' => 40, 'org_unit_id' => $theirs->id,
    ])->assertSessionHasErrors('org_unit_id');
});

it('records each step on both sides', function () {
    $project = collabProject();
    $participant = acceptProjectInvitation(inviteToProject($project, 'water-now'), 'water-now');
    $this->actingAsMember('acme', [], owner: true);
    $this->post($this->organizationUrl('acme', "projects/{$project->id}/collaboration/participants/{$participant->id}/status"), ['status' => 'completed'])->assertSessionHasNoErrors();

    $acme = $this->organization('acme')->id;
    $water = $this->organization('water-now')->id;
    $actions = fn (string $organization) => AuditEvent::query()->where('organization_id', $organization)->where('action', 'like', 'collaboration.%')->pluck('action')->all();

    expect($actions($acme))->toContain('collaboration.invited', 'collaboration.joined', 'collaboration.status_changed')
        ->and($actions($water))->toContain('collaboration.invitation_received', 'collaboration.accepted', 'collaboration.status_changed');
});

it('offers and keeps only the capabilities the application enforces', function () {
    $project = collabProject();

    // Not offered on the page, not suggested by a preset...
    $this->get($this->organizationUrl('acme', "projects/{$project->id}/collaboration"))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('capabilities', fn ($options) => collect($options)->pluck('value')->doesntContain('activities.manage')
            && collect($options)->pluck('value')->contains('reports.submit'))
        ->where('grantable', fn ($grantable) => collect($grantable)->doesntContain('activities.manage')));

    // ...and not kept when sent anyway, even by the owner.
    $this->post($this->organizationUrl('acme', "projects/{$project->id}/collaboration/invitations"), [
        'organization_id' => $this->organization('water-now')->id, 'relationship' => 'implementer', 'capabilities' => ['view', 'activities.manage', 'procurement.request', 'reports.submit'],
    ])->assertSessionHasNoErrors();
    expect(Invitation::query()->sole()->capabilities)->toBe(['view', 'reports.submit']);
});
