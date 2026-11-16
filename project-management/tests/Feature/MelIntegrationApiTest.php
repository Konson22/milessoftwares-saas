<?php

use App\Access\ProjectVisibilityScope;
use App\Models\Project;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/*
| What M&E reads from Projects (ADR-0019 §20): GET projects/integration/v1/…,
| with a platform read token bound to one organization and the member M&E
| acts for. The member gets what they would see in Projects, nothing more.
*/

const MEL_API = 'http://mel.internal/projects/integration/v1/';

beforeEach(function () {
    $this->project = collabProject('acme', 'WASH');
    $this->hidden = collabProject('acme', 'HIDE');
    inAcme(fn () => Project::query()->whereKey($this->hidden->id)->update(['visibility' => Project::VISIBLE_TO_TEAM]));
    $this->viewer = User::factory()->create();
    $this->platform()->grant('acme', $this->viewer->id, [['permissions' => ['projects.view']]]);
});

/**
 * @param  array{source?: string, act?: string|null, org?: string, overrides?: array<string, string>}  $o
 */
function melRead(string $path, array $o = []): TestResponse
{
    $token = test()->platform()->integrationToken(
        $o['org'] ?? test()->organization('acme')->id,
        $o['source'] ?? 'mel',
        'projects',
        array_key_exists('act', $o) ? $o['act'] : test()->viewer->id,
        $o['overrides'] ?? [],
    );

    return test()->withToken($token)->getJson(MEL_API.$path);
}

it('lists the projects the member may see, without team-only ones they are not on', function () {
    melRead('projects')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0', ['id' => $this->project->id, 'code' => 'WASH', 'name' => 'Clean water', 'label' => 'WASH · Clean water', 'status' => 'planning']);

    $owner = User::factory()->create();
    $this->platform()->grant('acme', $owner->id, owner: true);
    melRead('projects', ['act' => $owner->id])->assertOk()->assertJsonCount(2, 'data');
});

it('gives figures and milestones, money only to members who may see project money', function () {
    $ids = 'projects?ids[]='.$this->project->id.'&ids[]='.$this->hidden->id.'&with=milestones';

    melRead($ids)->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('missing', [$this->hidden->id])
        ->assertJsonPath('data.0.id', $this->project->id)
        ->assertJsonPath('data.0.approvedBudgetMinor', null)
        ->assertJsonPath('data.0.paidMinor', null)
        ->assertJsonPath('data.0.milestones.0.name', 'Wells drilled')
        ->assertJsonPath('data.0.milestones.0.dueOn', '2027-03-01')
        ->assertJsonPath('data.0.milestones.0.label', 'WASH · Wells drilled');

    $finance = User::factory()->create();
    $this->platform()->grant('acme', $finance->id, [['permissions' => ['projects.view', 'projects.finance.view']]]);
    melRead($ids, ['act' => $finance->id])->assertOk()->assertJsonPath('data.0.paidMinor', 0)->assertJsonPath('data.0.currency', $this->project->currency);
});

it('answers one project by id, still when it is completed, and hides one the member may not see', function () {
    inAcme(fn () => Project::query()->whereKey($this->project->id)->update(['status' => Project::COMPLETED]));

    melRead('projects/'.$this->project->id)->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.milestones.0.name', 'Wells drilled');
    melRead('projects/'.$this->hidden->id)->assertNotFound()->assertJsonPath('error', 'unknown_project');
    melRead('projects/not-a-uuid')->assertNotFound()->assertJsonPath('error', 'unknown_project');
});

it('gives milestones by id or by project, only of projects the member may see', function () {
    $milestone = inAcme(fn () => $this->project->milestones()->firstOrFail());
    $hiddenMilestone = inAcme(fn () => Project::query()->withoutGlobalScopes([ProjectVisibilityScope::class])->findOrFail($this->hidden->id)->milestones()->firstOrFail());

    melRead('milestones?ids[]='.$milestone->id.'&ids[]='.$hiddenMilestone->id)->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0', ['id' => $milestone->id, 'projectId' => $this->project->id, 'projectCode' => 'WASH', 'name' => 'Wells drilled', 'label' => 'WASH · Wells drilled', 'dueOn' => '2027-03-01', 'reachedOn' => null]);
    melRead('milestones?project_ids[]='.$this->project->id.'&project_ids[]='.$this->hidden->id)->assertOk()->assertJsonCount(1, 'data');
});

it('reads only the organization the platform signed for, whatever is asked', function () {
    collabProject('other', 'OTHR');
    $this->platform()->grant('other', $this->viewer->id, [['permissions' => ['projects.view']]]);

    // A member of both, reading for "other": acme's project is not there.
    melRead('projects?ids[]='.$this->project->id, ['org' => $this->organization('other')->id])
        ->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('missing', [$this->project->id]);
    melRead('projects/'.$this->project->id, ['org' => $this->organization('other')->id])->assertNotFound();
    melRead('projects?organization_id='.$this->organization('acme')->id, ['org' => $this->organization('other')->id])
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'OTHR');
});

it('refuses reads it should not answer', function (array $o, int $status, string $error) {
    melRead('projects', $o)->assertStatus($status)->assertJsonPath('error', $error)->assertHeader('X-Correlation-Id');
})->with([
    'no acting member' => [['act' => null], 403, 'acting_user_required'],
    'someone with no access in Projects' => [['act' => '0198a3b2-0000-7000-8000-000000000001'], 404, 'not_entitled'],
    'another product' => [['source' => 'finance'], 403, 'source_not_allowed'],
    'a token for another product' => [['overrides' => ['scope' => 'events.write']], 401, 'invalid_token'],
]);

it('refuses members without project access, and organizations that never opened Projects', function () {
    $mel = User::factory()->create();
    $this->platform()->grant('acme', $mel->id, [['permissions' => ['projects.documents.view']]]);
    melRead('projects', ['act' => $mel->id])->assertForbidden()->assertJsonPath('error', 'forbidden');

    $this->platform()->grant('acme', $mel->id, [['permissions' => ['projects.view']]], allowed: false);
    melRead('projects', ['act' => $mel->id])->assertForbidden()->assertJsonPath('error', 'forbidden');

    $this->platform()->organization('newco');
    melRead('projects', ['org' => $this->platform()->organization('newco')['id']])->assertNotFound()->assertJsonPath('error', 'not_provisioned');
});

it('refuses malformed id lists', function (string $query) {
    melRead($query)->assertStatus(422)->assertJsonPath('error', 'invalid');
})->with([
    'not uuids' => ['projects?ids[]=1'],
    'empty' => ['milestones'],
    'too many' => ['projects?'.implode('&', array_fill(0, 101, 'ids[]=0198a3b2-0000-7000-8000-000000000001'))],
]);

it('lists the organizations active on a project, for M&E to name a reporting partner', function () {
    acceptProjectInvitation(inviteToProject($this->project, 'lead-ngo', 'lead_implementer', ['view']), 'lead-ngo');
    $gone = acceptProjectInvitation(inviteToProject($this->project, 'field-ngo', 'implementer', ['view']), 'field-ngo');
    $this->actingAsMember('acme', [], owner: true);
    $this->post($this->organizationUrl('acme', "projects/{$this->project->id}/collaboration/participants/{$gone->id}/status"), ['status' => 'revoked'])->assertSessionHasNoErrors();

    melRead("projects/{$this->project->id}/participants")->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.organizationId', $this->organization('lead-ngo')->id)
        ->assertJsonPath('data.0.relationship', 'lead_implementer');

    // A project the member may not see is unknown.
    melRead("projects/{$this->hidden->id}/participants")->assertNotFound();
});
