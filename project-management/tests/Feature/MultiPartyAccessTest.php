<?php

use App\Collaboration\Models\Invitation;
use App\Collaboration\Models\Participant;
use App\Collaboration\Models\ProjectRequest;
use App\Collaboration\Models\Report;
use App\Enums\BudgetVersionStatus;
use App\Enums\CostCategory;
use App\Enums\ExpenseStatus;
use App\Models\BudgetVersion;
use App\Models\Person;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Financial ownership & collaboration phase: who sees what across
| organizations, in a direct donor–implementer project and in a multi-level
| one; and the read-only check of what Finance did with money events.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 09:00'));
    Notification::fake();
});

const MP_PARTNER = ['projects.view', 'projects.partners.manage', 'projects.reports.prepare', 'projects.reports.review', 'projects.requests.raise', 'projects.requests.review'];

/**
 * acme's project with an approved budget of 40,000, 500.00 paid and 9.99
 * of logged staff time.
 */
function mpFundedProject(): Project
{
    $project = collabProject();
    test()->asTenant('acme', function () use ($project) {
        $by = User::query()->firstOrFail()->id;
        $version = tap(tap(new BudgetVersion)->forceFill(['project_id' => $project->id, 'number' => 1, 'status' => BudgetVersionStatus::Approved, 'total_minor' => 4000000, 'created_by' => $by]))->save();
        $version->lines()->create(['project_id' => $project->id, 'category' => CostCategory::Materials, 'amount_minor' => 4000000]);
        $project->expenses()->create(['category' => CostCategory::Materials, 'description' => 'Pipes', 'amount_minor' => 50000, 'status' => ExpenseStatus::Paid, 'direct' => true, 'spent_on' => '2026-11-02', 'recorded_by' => $by]);
        $person = Person::query()->create(['name' => 'Engineer', 'hourly_cost_minor' => 999]);
        TimeEntry::query()->create(['project_id' => $project->id, 'person_id' => $person->id, 'worked_on' => '2026-11-03', 'minutes' => 60, 'cost_minor' => 999, 'created_by' => $by]);
    });

    return $project;
}

it('shows a donor of a direct project its budget only when given, and never staff cost or other projects', function () {
    $project = mpFundedProject();
    $other = collabProject('acme', 'ROAD');
    $donor = acceptProjectInvitation(inviteToProject($project, 'global-fund', 'donor', ['view']), 'global-fund');
    $url = $this->organizationUrl('global-fund', "shared-projects/{$donor->id}");

    $this->actingAsMember('global-fund', MP_PARTNER);
    $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('project.budget', null));

    // Given budget.view: the approved budget and what was paid, not the staff time.
    $this->actingAsMember('acme', [], owner: true);
    $this->put($this->organizationUrl('acme', "projects/{$project->id}/collaboration/participants/{$donor->id}"), ['capabilities' => ['view', 'budget.view']])->assertSessionHasNoErrors();
    $this->actingAsMember('global-fund', MP_PARTNER);
    $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('project.budget.approvedMinor', 4000000)->where('project.budget.spentMinor', 50000));

    // Nothing of the owner's other project, through any address.
    $this->get($this->organizationUrl('global-fund', "shared-projects/{$other->id}"))->assertNotFound();
    $this->get($this->organizationUrl('acme', "projects/{$other->id}"))->assertStatus(403);
});

it('keeps partners side by side from each other, and the owner\'s staff from requests they have no part in', function () {
    $project = collabProject();
    $north = acceptProjectInvitation(inviteToProject($project, 'water-now', 'implementer', ['view', 'reports.submit', 'requests.create']), 'water-now');
    $south = acceptProjectInvitation(inviteToProject($project, 'lead-ngo', 'implementer', ['view', 'reports.submit', 'requests.create']), 'lead-ngo');

    // North reports and asks for money, both to the owner.
    $this->actingAsMember('water-now', MP_PARTNER);
    $this->post($this->organizationUrl('water-now', "shared-projects/{$north->id}/reports"), ['title' => 'North October', 'category' => 'monthly'])->assertSessionHasNoErrors();
    $this->post($this->organizationUrl('water-now', "shared-projects/{$north->id}/requests"), [
        'type' => 'funding', 'title' => 'North tranche', 'justification' => 'Drilling', 'details' => ['amount_minor' => 2500000, 'currency' => 'USD'],
    ])->assertSessionHasNoErrors();
    $report = Report::query()->sole();
    $request = ProjectRequest::query()->sole();
    $this->post($this->organizationUrl('water-now', "shared-projects/{$north->id}/requests/{$request->id}/action"), ['action' => 'submit'])->assertSessionHasNoErrors();

    // South sees none of it.
    $this->actingAsMember('lead-ngo', MP_PARTNER);
    $this->get($this->organizationUrl('lead-ngo', "shared-projects/{$south->id}/reports/{$report->id}"))->assertNotFound();
    $this->get($this->organizationUrl('lead-ngo', "shared-projects/{$south->id}/requests/{$request->id}"))->assertNotFound();
    $this->get($this->organizationUrl('lead-ngo', "shared-projects/{$south->id}/requests"))->assertInertia(fn (Assert $page) => $page->has('requests', 0));
    // Nor by putting its own participation in front of north's records.
    $this->get($this->organizationUrl('lead-ngo', "shared-projects/{$north->id}/requests/{$request->id}"))->assertNotFound();

    // The owner's staff: projects.view alone opens no request (the amount is there).
    $this->actingAsMember('acme', ['projects.view']);
    $this->get($this->organizationUrl('acme', "projects/{$project->id}/requests"))->assertInertia(fn (Assert $page) => $page->has('requests', 0));
    $this->get($this->organizationUrl('acme', "projects/{$project->id}/requests/{$request->id}"))->assertNotFound();
    // A reviewer does.
    $this->actingAsMember('acme', ['projects.view', 'projects.requests.review']);
    $this->get($this->organizationUrl('acme', "projects/{$project->id}/requests/{$request->id}"))->assertOk();
});

it('opens a request only to the parties it involves, and there only to members who work on requests', function () {
    $project = collabProject();
    $lead = acceptProjectInvitation(inviteToProject($project, 'lead-ngo', 'lead_implementer', ['view', 'partners.manage', 'requests.create', 'requests.review']), 'lead-ngo');
    $donor = acceptProjectInvitation(inviteToProject($project, 'global-fund', 'donor', ['view', 'requests.create', 'requests.review']), 'global-fund');
    $this->actingAsMember('lead-ngo', COLLAB_MANAGER);
    $this->post($this->organizationUrl('lead-ngo', "shared-projects/{$lead->id}/invitations"), [
        'organization_id' => $this->organization('field-ngo')->id, 'relationship' => 'sub_implementer', 'capabilities' => ['view', 'requests.create'],
    ])->assertSessionHasNoErrors();
    $sub = acceptProjectInvitation(Invitation::query()->where('invited_organization_id', $this->organization('field-ngo')->id)->sole(), 'field-ngo');

    // The sub-partner asks its lead for money.
    $this->actingAsMember('field-ngo', ['projects.view', 'projects.partners.manage', 'projects.requests.raise']);
    $this->post($this->organizationUrl('field-ngo', "shared-projects/{$sub->id}/requests"), [
        'type' => 'funding', 'title' => 'Field tranche', 'justification' => 'Boreholes', 'details' => ['amount_minor' => 900_000, 'currency' => 'USD'],
    ])->assertSessionHasNoErrors();
    $request = ProjectRequest::query()->sole();
    $this->post($this->organizationUrl('field-ngo', "shared-projects/{$sub->id}/requests/{$request->id}/action"), ['action' => 'submit'])->assertSessionHasNoErrors();
    $at = fn (string $slug, Participant $party) => $this->organizationUrl($slug, "shared-projects/{$party->id}/requests/{$request->id}");
    $list = fn (string $slug, Participant $party) => $this->organizationUrl($slug, "shared-projects/{$party->id}/requests");

    // In the raising organization: raising or reviewing opens it; opening the project alone does not.
    $this->actingAsMember('field-ngo', ['projects.view', 'projects.partners.manage']);
    $this->get($at('field-ngo', $sub))->assertNotFound();
    $this->get($list('field-ngo', $sub))->assertInertia(fn (Assert $page) => $page->has('requests', 0));
    $this->actingAsMember('field-ngo', ['projects.view', 'projects.partners.manage', 'projects.requests.raise']);
    $this->get($at('field-ngo', $sub))->assertOk();

    // The lead reviews it: its reviewers see it, its raisers do not (it is not theirs).
    $this->actingAsMember('lead-ngo', ['projects.view', 'projects.partners.manage', 'projects.requests.raise']);
    $this->get($at('lead-ngo', $lead))->assertNotFound();
    $this->get($list('lead-ngo', $lead))->assertInertia(fn (Assert $page) => $page->has('requests', 0));
    $this->actingAsMember('lead-ngo', ['projects.view', 'projects.partners.manage', 'projects.requests.review']);
    $this->get($at('lead-ngo', $lead))->assertOk();

    // Not the donor, not the owner: the request was not sent to them.
    $this->actingAsMember('global-fund', ['projects.view', 'projects.partners.manage', 'projects.requests.raise', 'projects.requests.review']);
    $this->get($at('global-fund', $donor))->assertNotFound();
    $this->get($list('global-fund', $donor))->assertInertia(fn (Assert $page) => $page->has('requests', 0));
    $this->actingAsMember('acme', ['projects.view', 'projects.requests.raise', 'projects.requests.review']);
    $this->get($this->organizationUrl('acme', "projects/{$project->id}/requests/{$request->id}"))->assertNotFound();

    // The lead passes it up: the owner's reviewers see it now, the lead's reviewers keep sight of it, the donor still not.
    $this->actingAsMember('lead-ngo', ['projects.view', 'projects.partners.manage', 'projects.requests.review']);
    $this->post($this->organizationUrl('lead-ngo', "shared-projects/{$lead->id}/requests/{$request->id}/action"), ['action' => 'escalate', 'comment' => 'Beyond our share'])->assertSessionHasNoErrors();
    $this->get($at('lead-ngo', $lead))->assertOk();
    $this->get($list('lead-ngo', $lead))->assertInertia(fn (Assert $page) => $page->has('requests', 1));
    $this->actingAsMember('acme', ['projects.view', 'projects.requests.review']);
    $this->get($this->organizationUrl('acme', "projects/{$project->id}/requests/{$request->id}"))->assertOk();
    $this->actingAsMember('acme', ['projects.view', 'projects.requests.raise']);
    $this->get($this->organizationUrl('acme', "projects/{$project->id}/requests/{$request->id}"))->assertNotFound();
    $this->actingAsMember('global-fund', ['projects.view', 'projects.partners.manage', 'projects.requests.raise', 'projects.requests.review']);
    $this->get($at('global-fund', $donor))->assertNotFound();

    // A participation without request capabilities opens none, whatever its members hold.
    $this->actingAsMember('acme', [], owner: true);
    $this->put($this->organizationUrl('acme', "projects/{$project->id}/collaboration/participants/{$lead->id}"), ['capabilities' => ['view', 'partners.manage']])->assertSessionHasNoErrors();
    $this->actingAsMember('lead-ngo', ['projects.view', 'projects.partners.manage', 'projects.requests.raise', 'projects.requests.review']);
    $this->get($at('lead-ngo', $lead))->assertNotFound();
});
