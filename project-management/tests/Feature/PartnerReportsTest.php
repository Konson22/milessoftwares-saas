<?php

use App\Actions\Reminders;
use App\Collaboration\Models\Attachment;
use App\Collaboration\Models\Invitation;
use App\Collaboration\Models\Participant;
use App\Collaboration\Models\ProjectRequest;
use App\Collaboration\Models\Report;
use App\Collaboration\Models\ReportVersion;
use App\Collaboration\ReportPeriods;
use App\Enums\CostCategory;
use App\Models\AuditEvent;
use App\Models\BudgetVersion;
use App\Models\GrantDisbursement;
use App\Models\Project;
use App\Models\User;
use App\Models\UserNotification;
use App\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Partner reports and project requests (ADR-0015): each goes from one party
| of a project to another, by the route the participations make, never
| through a party that is not there; decisions are by someone other than
| the submitter; approval is not publication, and approving a request
| moves no money and changes no budget by itself.
*/

const PR_PARTNER = ['projects.view', 'projects.partners.manage', 'projects.reports.prepare', 'projects.reports.review', 'projects.reports.publish', 'projects.requests.raise', 'projects.requests.review'];

const PR_LEAD_CAPABILITIES = ['view', 'partners.manage', 'reports.submit', 'reports.review', 'reports.publish', 'requests.create', 'requests.review'];

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 09:00'));
    Notification::fake();
});

/**
 * acme owns the project; lead-ngo leads it; field-ngo works under the lead;
 * global-fund sees published reports.
 *
 * @return array{project: Project, lead: Participant, sub: Participant, donor: Participant}
 */
function prChain(array $subCapabilities = ['view', 'reports.submit', 'requests.create']): array
{
    $project = collabProject();
    $lead = acceptProjectInvitation(inviteToProject($project, 'lead-ngo', 'lead_implementer', PR_LEAD_CAPABILITIES), 'lead-ngo');
    $donor = acceptProjectInvitation(inviteToProject($project, 'global-fund', 'donor', ['view', 'donor_reports.view']), 'global-fund');

    test()->actingAsMember('lead-ngo', COLLAB_MANAGER);
    test()->post(test()->organizationUrl('lead-ngo', "shared-projects/{$lead->id}/invitations"), [
        'organization_id' => test()->organization('field-ngo')->id, 'relationship' => 'sub_implementer', 'capabilities' => $subCapabilities,
    ])->assertSessionHasNoErrors();
    $sub = acceptProjectInvitation(Invitation::query()->where('invited_organization_id', test()->organization('field-ngo')->id)->sole(), 'field-ngo');

    return ['project' => $project, 'lead' => $lead, 'sub' => $sub, 'donor' => $donor];
}

/**
 * @return array<string, mixed>
 */
function prContent(string $done = 'Drilled four wells'): array
{
    return ['content' => [
        'implementation' => ['activities_completed' => $done, 'delays' => 'Rains in October'],
        'narrative' => ['achievements' => 'Water reaches two villages'],
        'financial' => ['lines' => [['label' => 'Drilling', 'amountMinor' => 1200000]], 'note' => null],
    ]];
}

/**
 * A participant writes a report and submits it, signed in as a new member.
 */
function prSubmitted(string $slug, Participant $as, string $title = 'October progress'): Report
{
    test()->actingAsMember($slug, PR_PARTNER);
    test()->post(test()->organizationUrl($slug, "shared-projects/{$as->id}/reports"), ['title' => $title, 'category' => 'monthly', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31'])->assertSessionHasNoErrors();
    $report = Report::query()->where('title', $title)->sole();
    test()->put(test()->organizationUrl($slug, "shared-projects/{$as->id}/reports/{$report->id}"), prContent())->assertSessionHasNoErrors();
    test()->post(test()->organizationUrl($slug, "shared-projects/{$as->id}/reports/{$report->id}/action"), ['action' => 'submit'])->assertSessionHasNoErrors();

    return $report->refresh();
}

it('sends a sub-partner report to its lead, and only publication shows it to the donor', function () {
    ['project' => $project, 'lead' => $lead, 'sub' => $sub, 'donor' => $donor] = prChain();
    $report = prSubmitted('field-ngo', $sub);

    expect($report->status)->toBe(Report::SUBMITTED)
        ->and($report->reviewer_participant_id)->toBe($lead->id)
        ->and($report->currentVersion->isFrozen())->toBeTrue();

    // The donor and the owner do not see it yet.
    $this->actingAsMember('global-fund', PR_PARTNER);
    $this->get($this->organizationUrl('global-fund', "shared-projects/{$donor->id}/reports/{$report->id}"))->assertNotFound();
    $this->get($this->organizationUrl('global-fund', "shared-projects/{$donor->id}/reports"))->assertInertia(fn (Assert $page) => $page->has('reports', 0));
    $this->actingAsMember('acme', ['projects.view', 'projects.reports.review']);
    $this->get($this->organizationUrl('acme', "projects/{$project->id}/reports/{$report->id}"))->assertNotFound();
    // Sent outside any obligation, it stays between the sub-partner and its
    // lead: the owner does not even list it until it is published.
    $this->get($this->organizationUrl('acme', "projects/{$project->id}/reports"))->assertInertia(fn (Assert $page) => $page
        ->component('portal/collaboration/reports')->has('reports', 0));

    // The lead approves; approving is not publishing.
    $this->actingAsMember('lead-ngo', PR_PARTNER);
    $this->get($this->organizationUrl('lead-ngo', "shared-projects/{$lead->id}/reports/{$report->id}"))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal/collaboration/report')
        ->where('report.direction', 'incoming')
        ->where('version.content.implementation.activities_completed', 'Drilled four wells')
        ->where('can.decide', true));
    $this->post($this->organizationUrl('lead-ngo', "shared-projects/{$lead->id}/reports/{$report->id}/action"), ['action' => 'approve'])->assertSessionHasNoErrors();
    expect($report->refresh()->status)->toBe(Report::APPROVED);
    $this->actingAsMember('global-fund', PR_PARTNER);
    $this->get($this->organizationUrl('global-fund', "shared-projects/{$donor->id}/reports/{$report->id}"))->assertNotFound();

    $this->actingAsMember('lead-ngo', PR_PARTNER);
    $this->post($this->organizationUrl('lead-ngo', "shared-projects/{$lead->id}/reports/{$report->id}/action"), ['action' => 'publish'])->assertSessionHasNoErrors();
    expect($report->refresh()->status)->toBe(Report::PUBLISHED);

    $this->actingAsMember('global-fund', PR_PARTNER);
    $this->get($this->organizationUrl('global-fund', "shared-projects/{$donor->id}/reports/{$report->id}"))->assertOk();
    $this->actingAsMember('acme', ['projects.view']);
    $this->get($this->organizationUrl('acme', "projects/{$project->id}/reports/{$report->id}"))->assertOk();

    expect(AuditEvent::query()->where('action', 'partner_report.published')->where('organization_id', $project->organization_id)->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'partner_report.published')->where('organization_id', $this->organization('lead-ngo')->id)->exists())->toBeTrue();
});

it('sends a direct implementer to the owner, never to a lead that is not there', function () {
    $project = collabProject();
    $implementer = acceptProjectInvitation(inviteToProject($project, 'water-now', 'implementer', ['view', 'reports.submit']), 'water-now');

    $report = prSubmitted('water-now', $implementer);
    expect($report->reviewer_participant_id)->toBeNull();

    $this->actingAsMember('acme', ['projects.view', 'projects.reports.review']);
    $this->post($this->organizationUrl('acme', "projects/{$project->id}/reports/{$report->id}/action"), ['action' => 'start'])->assertSessionHasNoErrors();
    $this->post($this->organizationUrl('acme', "projects/{$project->id}/reports/{$report->id}/action"), ['action' => 'reject'])->assertSessionHasErrors('comment');
    $this->post($this->organizationUrl('acme', "projects/{$project->id}/reports/{$report->id}/action"), ['action' => 'approve'])->assertSessionHasNoErrors();
    expect($report->refresh()->status)->toBe(Report::APPROVED);
});

it('keeps a submitted version as it was, and makes a new one for changes', function () {
    ['lead' => $lead, 'sub' => $sub] = prChain();
    $report = prSubmitted('field-ngo', $sub);
    $first = $report->currentVersion;

    // Nobody decides what they submitted.
    $submitter = User::query()->findOrFail($first->submitted_by);
    $this->actingAsMember('field-ngo', PR_PARTNER, user: $submitter);
    $this->put($this->organizationUrl('field-ngo', "shared-projects/{$sub->id}/reports/{$report->id}"), prContent('Changed my mind'))->assertSessionHasErrors('report');

    $this->actingAsMember('lead-ngo', PR_PARTNER);
    $this->post($this->organizationUrl('lead-ngo', "shared-projects/{$lead->id}/reports/{$report->id}/action"), ['action' => 'changes', 'comment' => 'Add the second village'])->assertSessionHasNoErrors();

    $this->actingAsMember('field-ngo', PR_PARTNER, user: $submitter);
    $this->put($this->organizationUrl('field-ngo', "shared-projects/{$sub->id}/reports/{$report->id}"), prContent('Drilled four wells in two villages'))->assertSessionHasNoErrors();
    $this->post($this->organizationUrl('field-ngo', "shared-projects/{$sub->id}/reports/{$report->id}/action"), ['action' => 'submit'])->assertSessionHasNoErrors();

    $report->refresh();
    expect($report->status)->toBe(Report::SUBMITTED)
        ->and($report->currentVersion->number)->toBe(2)
        ->and($first->refresh()->content['implementation']['activities_completed'])->toBe('Drilled four wells')
        ->and($report->reviews()->pluck('action')->all())->toBe(['submitted', 'changes_requested', 'resubmitted']);

    // The reviewer may read the first version too.
    $this->actingAsMember('lead-ngo', PR_PARTNER);
    $this->get($this->organizationUrl('lead-ngo', "shared-projects/{$lead->id}/reports/{$report->id}?version=1"))->assertInertia(fn (Assert $page) => $page
        ->where('version.number', 1)->has('versions', 2));
});

it('refuses a frozen version at the database too', function () {
    ['sub' => $sub] = prChain();
    $report = prSubmitted('field-ngo', $sub);

    expect(fn () => ReportVersion::query()->whereKey($report->current_version_id)->update(['content' => json_encode(['changed' => true])]))
        ->toThrow(QueryException::class);
})->skip(fn () => config('database.default') !== 'pgsql', 'PostgreSQL trigger');

it('does not let the submitter decide their own report', function () {
    $project = collabProject();
    // The owner reports internally: someone else must review it.
    $writer = $this->actingAsMember('acme', ['projects.view', 'projects.reports.prepare', 'projects.reports.review']);
    $this->post($this->organizationUrl('acme', "projects/{$project->id}/reports"), ['title' => 'Quarter one', 'category' => 'quarterly', 'reviewer' => 'owner'])->assertSessionHasNoErrors();
    $report = Report::query()->sole();
    $this->put($this->organizationUrl('acme', "projects/{$project->id}/reports/{$report->id}"), prContent())->assertSessionHasNoErrors();
    $this->post($this->organizationUrl('acme', "projects/{$project->id}/reports/{$report->id}/action"), ['action' => 'submit'])->assertSessionHasNoErrors();
    $this->post($this->organizationUrl('acme', "projects/{$project->id}/reports/{$report->id}/action"), ['action' => 'approve'])->assertSessionHasErrors('report');
    expect($report->refresh()->status)->toBe(Report::SUBMITTED);

    $this->actingAsMember('acme', ['projects.view', 'projects.reports.review']);
    $this->post($this->organizationUrl('acme', "projects/{$project->id}/reports/{$report->id}/action"), ['action' => 'approve'])->assertSessionHasNoErrors();
    expect($report->refresh()->status)->toBe(Report::APPROVED)->and($writer->id)->not->toBe($report->reviews()->where('action', 'approved')->value('user_id'));
});

it('acts only on what the participation was given, while it stands', function () {
    $project = collabProject();
    $viewer = acceptProjectInvitation(inviteToProject($project, 'water-now', 'implementer', ['view']), 'water-now');

    // Permission in its own organization is not enough without the capability.
    $this->actingAsMember('water-now', PR_PARTNER);
    $this->post($this->organizationUrl('water-now', "shared-projects/{$viewer->id}/reports"), ['title' => 'Mine', 'category' => 'monthly'])->assertForbidden();
    $this->post($this->organizationUrl('water-now', "shared-projects/{$viewer->id}/requests"), ['type' => 'information', 'title' => 'Maps', 'justification' => 'Planning', 'details' => ['asked' => 'Village maps']])->assertForbidden();

    // Nor the capability without the permission.
    $implementer = acceptProjectInvitation(inviteToProject($project, 'field-ngo', 'implementer', ['view', 'reports.submit']), 'field-ngo');
    $this->actingAsMember('field-ngo', COLLAB_MANAGER);
    $this->post($this->organizationUrl('field-ngo', "shared-projects/{$implementer->id}/reports"), ['title' => 'Mine', 'category' => 'monthly'])->assertForbidden();

    // Revoked, it reaches nothing it wrote before.
    $report = prSubmitted('field-ngo', $implementer);
    $this->actingAsMember('acme', COLLAB_MANAGER);
    $this->post($this->organizationUrl('acme', "projects/{$project->id}/collaboration/participants/{$implementer->id}/status"), ['status' => 'revoked'])->assertSessionHasNoErrors();
    $this->actingAsMember('field-ngo', PR_PARTNER);
    $this->get($this->organizationUrl('field-ngo', "shared-projects/{$implementer->id}/reports/{$report->id}"))->assertForbidden();
    // Its history stays.
    expect(Report::query()->whereKey($report->id)->exists())->toBeTrue();
});

it('records the budget with a submission only for a party that may see it', function () {
    ['sub' => $sub] = prChain();
    $report = prSubmitted('field-ngo', $sub);

    $content = $report->currentVersion->content;
    expect($content['system']['financial'])->toBeNull()
        ->and($content['financial']['lines'][0]['source'])->toBe('reported');
});

it('records no M&E results with a submission when Projects does not connect to M&E (ADR-0019; see PartnerReportMelTest)', function () {
    ['project' => $project, 'lead' => $lead] = prChain();
    // An M&E performance report is still a report a partner writes.
    $this->actingAsMember('lead-ngo', PR_PARTNER);
    $this->post($this->organizationUrl('lead-ngo', "shared-projects/{$lead->id}/reports"), ['title' => 'Q3 results', 'category' => 'mel', 'reviewer' => 'owner', 'period_start' => '2026-07-01', 'period_end' => '2026-09-30'])->assertSessionHasNoErrors();
    $report = Report::query()->where('title', 'Q3 results')->sole();
    $this->put($this->organizationUrl('lead-ngo', "shared-projects/{$lead->id}/reports/{$report->id}"), prContent())->assertSessionHasNoErrors();
    $this->post($this->organizationUrl('lead-ngo', "shared-projects/{$lead->id}/reports/{$report->id}/action"), ['action' => 'submit'])->assertSessionHasNoErrors();

    expect($report->refresh()->category)->toBe('mel')
        ->and($report->currentVersion->content['system']['mel'])->toBe([])
        ->and($report->currentVersion->content['system']['melSource']['status'])->toBe('off');

    $this->actingAsMember('acme', ['projects.view', 'projects.reports.review']);
    $this->get($this->organizationUrl('acme', "projects/{$project->id}/reports/{$report->id}"))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('portal/collaboration/report')->where('version.content.system.mel', []));
});

it('generates the periods of an obligation once, and tracks what is late', function () {
    ['project' => $project, 'lead' => $lead, 'sub' => $sub] = prChain();

    // Only the owner's reporting managers set obligations.
    $this->actingAsMember('acme', ['projects.view']);
    $this->post($this->organizationUrl('acme', "projects/{$project->id}/reports/obligations"), [])->assertForbidden();

    $this->actingAsMember('acme', ['projects.view', 'projects.reporting.manage']);
    // A reviewer that may not review is refused.
    $this->post($this->organizationUrl('acme', "projects/{$project->id}/reports/obligations"), [
        'title' => 'Monthly progress', 'category' => 'monthly', 'frequency' => 'monthly', 'starts_on' => '2026-08-01', 'ends_on' => '2026-12-31', 'due_days' => 10,
        'responsible' => $sub->id, 'reviewer' => $sub->id,
    ])->assertSessionHasErrors('reviewer');
    $this->post($this->organizationUrl('acme', "projects/{$project->id}/reports/obligations"), [
        'title' => 'Monthly progress', 'category' => 'monthly', 'frequency' => 'monthly', 'starts_on' => '2026-08-01', 'ends_on' => '2026-12-31', 'due_days' => 10,
        'responsible' => $sub->id, 'reviewer' => $lead->id,
    ])->assertSessionHasNoErrors();

    $reports = Report::query()->orderBy('period_start')->get();
    expect($reports)->toHaveCount(5)
        ->and($reports->first()->due_on->toDateString())->toBe('2026-09-10')
        ->and($reports->last()->period_end->toDateString())->toBe('2026-12-31')
        ->and($reports->filter->isOverdue()->count())->toBe(3) // August, September and October (due 10 November)
        ->and($reports->pluck('reviewer_participant_id')->unique()->all())->toBe([$lead->id]);

    // The daily run reminds those at field-ngo who prepare its reports, once per due date.
    $this->actingAsMember('field-ngo', PR_PARTNER);
    $run = fn () => app(CurrentOrganization::class)->run($this->organization('field-ngo'), fn () => Reminders::run());
    $told = fn () => $this->asTenant('field-ngo', fn () => UserNotification::query()->where('dedupe_key', 'like', 'partner-report-%')->count());
    $run();
    $first = $told();
    $run();
    expect($first)->toBe(3)->and($told())->toBe(3);
});

it('splits periods without drifting from a month end', function () {
    $periods = ReportPeriods::between('monthly', CarbonImmutable::parse('2027-01-31'), CarbonImmutable::parse('2027-04-30'));

    expect(array_map(fn ($period) => $period['start']->toDateString(), $periods))->toBe(['2027-01-31', '2027-02-28', '2027-03-31', '2027-04-30']);
    expect(fn () => ReportPeriods::between('monthly', CarbonImmutable::parse('2020-01-01'), CarbonImmutable::parse('2035-01-01')))->toThrow(ValidationException::class);
});

it('consolidates approved sub-partner reports by listing them, never adding them up', function () {
    ['lead' => $lead, 'sub' => $sub] = prChain();
    $source = prSubmitted('field-ngo', $sub);
    $this->actingAsMember('lead-ngo', PR_PARTNER);
    $this->post($this->organizationUrl('lead-ngo', "shared-projects/{$lead->id}/reports/{$source->id}/action"), ['action' => 'approve'])->assertSessionHasNoErrors();

    $this->post($this->organizationUrl('lead-ngo', "shared-projects/{$lead->id}/reports"), ['title' => 'Consolidated October', 'category' => 'monthly'])->assertSessionHasNoErrors();
    $consolidated = Report::query()->where('title', 'Consolidated October')->sole();
    expect($consolidated->reviewer_participant_id)->toBeNull(); // the lead's own route: the owner

    $this->post($this->organizationUrl('lead-ngo', "shared-projects/{$lead->id}/reports/{$consolidated->id}/sources"), ['source' => $source->id])->assertSessionHasNoErrors();
    $this->get($this->organizationUrl('lead-ngo', "shared-projects/{$lead->id}/reports/{$consolidated->id}"))->assertInertia(fn (Assert $page) => $page
        ->has('sources', 1)
        ->where('sources.0.reference', $source->reference())
        ->where('sources.0.content.financial.lines.0.amountMinor', 1200000));
    expect($source->refresh()->status)->toBe(Report::APPROVED);

    // Only an approved report sent to it.
    $draft = Report::query()->where('title', 'Consolidated October')->sole();
    $this->post($this->organizationUrl('lead-ngo', "shared-projects/{$lead->id}/reports/{$consolidated->id}/sources"), ['source' => $draft->id])->assertSessionHasErrors('source');
});

it('decides a funding request without creating or receiving any money', function () {
    $project = collabProject();
    $implementer = acceptProjectInvitation(inviteToProject($project, 'water-now', 'implementer', ['view', 'requests.create']), 'water-now');

    $this->actingAsMember('water-now', PR_PARTNER);
    $this->post($this->organizationUrl('water-now', "shared-projects/{$implementer->id}/requests"), [
        'type' => 'funding', 'title' => 'Second tranche', 'justification' => 'Drilling season',
        'details' => ['amount_minor' => 0, 'currency' => 'USD'],
    ])->assertSessionHasErrors('amount_minor');
    $this->post($this->organizationUrl('water-now', "shared-projects/{$implementer->id}/requests"), [
        'type' => 'funding', 'title' => 'Second tranche', 'justification' => 'Drilling season',
        'details' => ['amount_minor' => 2500000, 'currency' => 'usd', 'agreement_reference' => 'AGR-7'],
    ])->assertSessionHasNoErrors();
    $request = ProjectRequest::query()->sole();
    expect($request->reference())->toBe('REQ-1')->and($request->currency)->toBe('USD')->and($request->reviewer_participant_id)->toBeNull();

    // A draft is not seen by the reviewer.
    $this->actingAsMember('acme', ['projects.view', 'projects.requests.review']);
    $this->get($this->organizationUrl('acme', "projects/{$project->id}/requests/{$request->id}"))->assertNotFound();

    $this->actingAsMember('water-now', PR_PARTNER);
    $this->post($this->organizationUrl('water-now', "shared-projects/{$implementer->id}/requests/{$request->id}/action"), ['action' => 'submit'])->assertSessionHasNoErrors();

    $this->actingAsMember('acme', ['projects.view', 'projects.requests.review']);
    $this->post($this->organizationUrl('acme', "projects/{$project->id}/requests/{$request->id}/action"), ['action' => 'approve'])->assertSessionHasNoErrors();
    $this->get($this->organizationUrl('acme', "projects/{$project->id}/requests/{$request->id}"))->assertInertia(fn (Assert $page) => $page
        ->component('portal/collaboration/request')
        ->where('request.fundingState', 'approved_not_scheduled'));

    $request->refresh();
    expect($request->status)->toBe(ProjectRequest::APPROVED)
        ->and($request->grant_disbursement_id)->toBeNull()
        ->and(GrantDisbursement::acrossOrganizations()->count())->toBe(0)
        ->and($request->decisions()->where('action', 'submitted')->value('snapshot')['amountMinor'])->toBe(2500000);

    // Linking is the owner's, to a payment on a grant funding the project.
    $this->actingAsMember('acme', ['projects.view', 'grants.manage']);
    $this->post($this->organizationUrl('acme', "projects/{$project->id}/requests/{$request->id}/follow-up"), ['action' => 'disbursement', 'disbursement' => (string) Str::uuid()])->assertSessionHasErrors('disbursement');
});

it('opens a budget revision through the budget workflow, leaving approved figures alone', function () {
    $project = collabProject();
    $implementer = acceptProjectInvitation(inviteToProject($project, 'water-now', 'implementer', ['view', 'requests.create']), 'water-now');
    $this->actingAsMember('water-now', PR_PARTNER);
    $this->post($this->organizationUrl('water-now', "shared-projects/{$implementer->id}/requests"), [
        'type' => 'budget_revision', 'title' => 'Move money to drilling', 'justification' => 'Costs rose',
        'details' => ['changes' => [['category' => CostCategory::cases()[0]->value, 'current_minor' => 100000, 'proposed_minor' => 150000, 'reason' => 'Fuel']]],
    ])->assertSessionHasNoErrors();
    $request = ProjectRequest::query()->sole();
    $this->post($this->organizationUrl('water-now', "shared-projects/{$implementer->id}/requests/{$request->id}/action"), ['action' => 'submit'])->assertSessionHasNoErrors();

    $before = $this->asTenant('acme', fn () => BudgetVersion::query()->where('project_id', $project->id)->get(['id', 'status', 'total_minor'])->toArray());

    // Not before approval.
    $this->actingAsMember('acme', ['projects.view', 'projects.manage', 'projects.finance.view', 'projects.requests.review']);
    $this->post($this->organizationUrl('acme', "projects/{$project->id}/requests/{$request->id}/follow-up"), ['action' => 'budget'])->assertSessionHasErrors('request');
    $this->post($this->organizationUrl('acme', "projects/{$project->id}/requests/{$request->id}/action"), ['action' => 'approve'])->assertSessionHasNoErrors();
    $this->post($this->organizationUrl('acme', "projects/{$project->id}/requests/{$request->id}/follow-up"), ['action' => 'budget'])->assertSessionHasNoErrors();

    $request->refresh();
    $draft = $this->asTenant('acme', fn () => BudgetVersion::query()->findOrFail($request->budget_version_id));
    expect($draft->isDraft())->toBeTrue()
        ->and($this->asTenant('acme', fn () => BudgetVersion::query()->whereIn('id', array_column($before, 'id'))->get(['id', 'status', 'total_minor'])->toArray()))->toBe($before);
    // Once only.
    $this->post($this->organizationUrl('acme', "projects/{$project->id}/requests/{$request->id}/follow-up"), ['action' => 'budget'])->assertSessionHasErrors('request');
});

it('escalates a request up the chain, and the parties it passed keep sight of it', function () {
    ['project' => $project, 'lead' => $lead, 'sub' => $sub] = prChain();

    $this->actingAsMember('field-ngo', PR_PARTNER);
    $this->post($this->organizationUrl('field-ngo', "shared-projects/{$sub->id}/requests"), [
        'type' => 'timeline_extension', 'title' => 'Three more months', 'justification' => 'Floods',
        'details' => ['current_end' => '2027-10-31', 'proposed_end' => '2028-01-31'],
    ])->assertSessionHasNoErrors();
    $request = ProjectRequest::query()->sole();
    expect($request->reviewer_participant_id)->toBe($lead->id);
    $this->post($this->organizationUrl('field-ngo', "shared-projects/{$sub->id}/requests/{$request->id}/action"), ['action' => 'submit'])->assertSessionHasNoErrors();

    $this->actingAsMember('lead-ngo', PR_PARTNER);
    $this->post($this->organizationUrl('lead-ngo', "shared-projects/{$lead->id}/requests/{$request->id}/action"), ['action' => 'escalate'])->assertSessionHasErrors('comment');
    $this->post($this->organizationUrl('lead-ngo', "shared-projects/{$lead->id}/requests/{$request->id}/action"), ['action' => 'escalate', 'comment' => 'Beyond what we can agree'])->assertSessionHasNoErrors();
    expect($request->refresh()->reviewer_participant_id)->toBeNull();

    // The lead still sees it, but no longer decides.
    $this->get($this->organizationUrl('lead-ngo', "shared-projects/{$lead->id}/requests/{$request->id}"))->assertOk()->assertInertia(fn (Assert $page) => $page->where('can.decide', false));
    $this->post($this->organizationUrl('lead-ngo', "shared-projects/{$lead->id}/requests/{$request->id}/action"), ['action' => 'approve'])->assertForbidden();

    // The owner decides and applies the new date.
    $this->actingAsMember('acme', ['projects.view', 'projects.requests.review', 'projects.changes.approve']);
    $this->post($this->organizationUrl('acme', "projects/{$project->id}/requests/{$request->id}/action"), ['action' => 'approve'])->assertSessionHasNoErrors();
    $this->post($this->organizationUrl('acme', "projects/{$project->id}/requests/{$request->id}/follow-up"), ['action' => 'timeline'])->assertSessionHasNoErrors();
    expect($this->asTenant('acme', fn () => Project::query()->findOrFail($project->id)->due_on->toDateString()))->toBe('2028-01-31')
        ->and(AuditEvent::query()->where('action', 'project_request.timeline_applied')->exists())->toBeTrue();

    // The owner, at the top, has nobody to escalate to.
    $this->post($this->organizationUrl('acme', "projects/{$project->id}/requests/{$request->id}/action"), ['action' => 'escalate', 'comment' => 'x'])->assertForbidden();
});

it('lets the raiser cancel, and never decide, their own request', function () {
    $project = collabProject();
    $raiser = $this->actingAsMember('acme', ['projects.view', 'projects.requests.raise', 'projects.requests.review']);
    $this->post($this->organizationUrl('acme', "projects/{$project->id}/requests"), [
        'type' => 'information', 'title' => 'Site photos', 'justification' => 'For the file', 'details' => ['asked' => 'Photos of each well'],
    ])->assertSessionHasNoErrors();
    $request = ProjectRequest::query()->sole();
    $this->post($this->organizationUrl('acme', "projects/{$project->id}/requests/{$request->id}/action"), ['action' => 'submit'])->assertSessionHasNoErrors();
    $this->post($this->organizationUrl('acme', "projects/{$project->id}/requests/{$request->id}/action"), ['action' => 'approve'])->assertSessionHasErrors('request');
    $this->post($this->organizationUrl('acme', "projects/{$project->id}/requests/{$request->id}/action"), ['action' => 'cancel'])->assertSessionHasNoErrors();
    expect($request->refresh()->status)->toBe(ProjectRequest::CANCELLED)->and($raiser->id)->toBe($request->raised_by);
});

it('keeps decisions and reviews append-only at the database', function () {
    ['sub' => $sub] = prChain();
    $report = prSubmitted('field-ngo', $sub);

    expect(fn () => DB::table('project_report_reviews')->where('report_id', $report->id)->delete())->toThrow(QueryException::class);
})->skip(fn () => config('database.default') !== 'pgsql', 'PostgreSQL trigger');

it('keeps attachments of a draft to its writer, and of a submission to both parties', function () {
    Storage::fake('local');
    ['lead' => $lead, 'sub' => $sub] = prChain();

    $writer = $this->actingAsMember('field-ngo', PR_PARTNER);
    $this->post($this->organizationUrl('field-ngo', "shared-projects/{$sub->id}/reports"), ['title' => 'With photos', 'category' => 'activity'])->assertSessionHasNoErrors();
    $report = Report::query()->sole();
    $this->put($this->organizationUrl('field-ngo', "shared-projects/{$sub->id}/reports/{$report->id}"), prContent())->assertSessionHasNoErrors();
    $this->post($this->organizationUrl('field-ngo', "shared-projects/{$sub->id}/reports/{$report->id}/attachments"), [
        'file' => UploadedFile::fake()->create('wells.pdf', 120, 'application/pdf'),
    ])->assertSessionHasNoErrors();
    $attachment = Attachment::query()->sole();
    $this->get($this->organizationUrl('field-ngo', "shared-projects/{$sub->id}/reports/{$report->id}/attachments/{$attachment->id}"))->assertOk();

    $this->post($this->organizationUrl('field-ngo', "shared-projects/{$sub->id}/reports/{$report->id}/action"), ['action' => 'submit'])->assertSessionHasNoErrors();
    $this->actingAsMember('lead-ngo', PR_PARTNER);
    $this->get($this->organizationUrl('lead-ngo', "shared-projects/{$lead->id}/reports/{$report->id}/attachments/{$attachment->id}"))->assertOk();
    // An outsider never.
    $this->actingAsMember('global-fund', PR_PARTNER);
    $donor = Participant::query()->where('participant_organization_id', $this->organization('global-fund')->id)->sole();
    $this->get($this->organizationUrl('global-fund', "shared-projects/{$donor->id}/reports/{$report->id}/attachments/{$attachment->id}"))->assertNotFound();
    expect($writer->id)->toBe($attachment->uploaded_by);
});

/*
| Stabilization: what an ended obligation does to its reports, and who reads
| the financial section.
*/

it('cancels unsent reports of an ended obligation, and keeps them from showing as overdue or being resubmitted', function () {
    ['project' => $project, 'lead' => $lead, 'sub' => $sub] = prChain();
    $this->actingAsMember('acme', ['projects.view', 'projects.reporting.manage']);
    $this->post($this->organizationUrl('acme', "projects/{$project->id}/reports/obligations"), [
        'title' => 'Monthly progress', 'category' => 'monthly', 'frequency' => 'monthly', 'starts_on' => '2026-08-01', 'ends_on' => '2026-12-31', 'due_days' => 10,
        'responsible' => $sub->id, 'reviewer' => $lead->id,
    ])->assertSessionHasNoErrors();
    $byMonth = fn () => Report::query()->orderBy('period_start')->get()->keyBy(fn (Report $report) => $report->period_start->format('M'));
    $subUrl = fn (string $path = '') => $this->organizationUrl('field-ngo', "shared-projects/{$sub->id}/reports".$path);
    $leadUrl = fn (string $path = '') => $this->organizationUrl('lead-ngo', "shared-projects/{$lead->id}/reports".$path);

    // August: submitted, then withdrawn by field-ngo. September: the lead asks for changes.
    $field = $this->actingAsMember('field-ngo', PR_PARTNER);
    foreach (['Aug', 'Sep'] as $month) {
        $this->put($subUrl("/{$byMonth()[$month]->id}"), prContent())->assertSessionHasNoErrors();
        $this->post($subUrl("/{$byMonth()[$month]->id}/action"), ['action' => 'submit'])->assertSessionHasNoErrors();
    }
    $this->post($subUrl("/{$byMonth()['Aug']->id}/action"), ['action' => 'withdraw'])->assertSessionHasNoErrors();
    $this->actingAsMember('lead-ngo', PR_PARTNER);
    $this->post($leadUrl("/{$byMonth()['Sep']->id}/action"), ['action' => 'changes', 'comment' => 'Add the October figures'])->assertSessionHasNoErrors();
    $reviewsBefore = DB::table('project_report_reviews')->count();

    // The owner ends the obligation.
    $this->actingAsMember('acme', ['projects.view', 'projects.reporting.manage']);
    $obligation = DB::table('report_obligations')->value('id');
    $this->post($this->organizationUrl('acme', "projects/{$project->id}/reports/obligations/{$obligation}/end"))->assertSessionHasNoErrors();

    $reports = $byMonth();
    expect($reports['Aug']->status)->toBe(Report::WITHDRAWN)
        ->and($reports['Sep']->status)->toBe(Report::CHANGES_REQUESTED)
        ->and(collect(['Oct', 'Nov', 'Dec'])->map(fn ($month) => $reports[$month]->status)->unique()->values()->all())->toBe([Report::CANCELLED])
        // Nothing of an ended obligation is overdue, whatever its status.
        ->and($reports->filter->isOverdue()->count())->toBe(0)
        // History stays: three "cancelled" steps added, nothing removed.
        ->and(DB::table('project_report_reviews')->count())->toBe($reviewsBefore + 3)
        ->and(DB::table('project_report_reviews')->where('action', 'cancelled')->count())->toBe(3);

    // A cancelled report and a withdrawn one can no longer be written or sent;
    // the changes the lead asked for can.
    $this->actingAsMember('field-ngo', PR_PARTNER, user: $field);
    $this->put($subUrl("/{$reports['Oct']->id}"), prContent())->assertSessionHasErrors('report');
    $this->post($subUrl("/{$reports['Aug']->id}/action"), ['action' => 'submit'])->assertSessionHasErrors('report');
    $this->put($subUrl("/{$reports['Aug']->id}"), prContent())->assertSessionHasErrors('report');
    $this->put($subUrl("/{$reports['Sep']->id}"), prContent('Five wells'))->assertSessionHasNoErrors();
    $this->post($subUrl("/{$reports['Sep']->id}/action"), ['action' => 'submit'])->assertSessionHasNoErrors();
    expect($reports['Sep']->refresh()->status)->toBe(Report::SUBMITTED);

    // And the daily run reminds nobody of them.
    app(CurrentOrganization::class)->run($this->organization('field-ngo'), fn () => Reminders::run());
    expect($this->asTenant('field-ngo', fn () => UserNotification::query()->where('dedupe_key', 'like', 'partner-report-%')->count()))->toBe(0);
});

it('refuses changes to a frozen version and to review history in the application, on any database', function () {
    ['sub' => $sub] = prChain();
    $report = prSubmitted('field-ngo', $sub);
    $version = ReportVersion::query()->findOrFail($report->current_version_id);

    expect(fn () => $version->forceFill(['content' => ['changed' => true]])->save())->toThrow(LogicException::class)
        ->and(fn () => $version->delete())->toThrow(LogicException::class)
        ->and(fn () => $report->reviews()->firstOrFail()->forceFill(['comment' => 'edited'])->save())->toThrow(LogicException::class)
        ->and(fn () => $report->reviews()->firstOrFail()->delete())->toThrow(LogicException::class);
});

it('keeps the reported financial section from owner members without the finance permission', function () {
    ['project' => $project, 'lead' => $lead] = prChain();
    // The lead reports straight to the owner.
    $this->actingAsMember('lead-ngo', PR_PARTNER);
    $this->post($this->organizationUrl('lead-ngo', "shared-projects/{$lead->id}/reports"), ['title' => 'Q3', 'category' => 'quarterly', 'reviewer' => 'owner'])->assertSessionHasNoErrors();
    $report = Report::query()->where('title', 'Q3')->sole();
    $this->put($this->organizationUrl('lead-ngo', "shared-projects/{$lead->id}/reports/{$report->id}"), prContent())->assertSessionHasNoErrors();
    $this->post($this->organizationUrl('lead-ngo', "shared-projects/{$lead->id}/reports/{$report->id}/action"), ['action' => 'submit'])->assertSessionHasNoErrors();
    $url = $this->organizationUrl('acme', "projects/{$project->id}/reports/{$report->id}");

    // A reviewer reads the figures it is asked to review.
    $this->actingAsMember('acme', ['projects.view', 'projects.reports.review']);
    $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('version.content.financial.lines.0.label', 'Drilling')->missing('version.content.financialHidden'));
    // Someone who only sees projects does not open the unpublished report at all.
    $this->actingAsMember('acme', ['projects.view']);
    $this->get($url)->assertNotFound();
    $this->get($this->organizationUrl('acme', "projects/{$project->id}/reports"))->assertInertia(fn (Assert $page) => $page->has('reports', 0));

    // Approved and published: everyone with projects.view reads it, the
    // reported figures only with projects.finance.view.
    $this->actingAsMember('acme', ['projects.view', 'projects.reports.review', 'projects.reports.publish']);
    $this->post("{$url}/action", ['action' => 'approve'])->assertSessionHasNoErrors();
    $this->post("{$url}/action", ['action' => 'publish'])->assertSessionHasNoErrors();
    $this->actingAsMember('acme', ['projects.view']);
    $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('version.content.financialHidden', true)->where('version.content.financial.lines', []));
    $this->actingAsMember('acme', ['projects.view', 'projects.finance.view']);
    $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('version.content.financial.lines.0.amountMinor', 1200000));
});
