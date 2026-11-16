<?php

use App\Collaboration\Models\Invitation;
use App\Collaboration\Models\Participant;
use App\Collaboration\Models\Report;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/*
| M&E results recorded with a partner report (ADR-0019 §20): read from the
| M&E application at submission, for the project's owner organization, and
| frozen with the version. When M&E cannot answer, the report says why and
| goes in all the same; it never records "no results" it was not told.
*/

const MR_PARTNER = ['projects.view', 'projects.partners.manage', 'projects.reports.prepare', 'projects.reports.review', 'projects.reports.publish', 'projects.requests.raise', 'projects.requests.review'];

const MR_URL = 'http://mel.internal/mel/integration/v1';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 09:00'));
    Notification::fake();
    $this->project = collabProject();
    $this->lead = acceptProjectInvitation(inviteToProject($this->project, 'lead-ngo', 'lead_implementer', ['view', 'reports.submit', 'reports.review']), 'lead-ngo');
    // What M&E answers next (a response, or a closure throwing).
    $this->mel = Http::response(['linked' => true, 'units' => 1, 'shared' => 1, 'truncated' => false, 'data' => [melRow('120.000000')]]);
});

/**
 * @return array<string, mixed>
 */
function melRow(string $value, string $period = '2026 Q3'): array
{
    return [
        'id' => '0198a3b2-0000-7000-8000-0000000000e1', 'indicatorId' => '0198a3b2-0000-7000-8000-0000000000e2', 'indicatorVersionId' => '0198a3b2-0000-7000-8000-0000000000e3',
        'indicatorCode' => 'OP1', 'indicator' => 'People with access to safe water', 'measure' => 'count', 'unitLabel' => 'people',
        'unitId' => '0198a3b2-0000-7000-8000-0000000000e4', 'unit' => 'Juba', 'periodId' => '0198a3b2-0000-7000-8000-0000000000e5', 'period' => $period,
        'periodStartsOn' => '2026-07-01', 'periodEndsOn' => '2026-09-30', 'value' => $value, 'numerator' => null, 'denominator' => null,
        'verifiedAt' => '2026-10-05T10:00:00+00:00', 'publishedAt' => '2026-10-06T10:00:00+00:00',
        'evidence' => [['name' => 'register.pdf', 'mimeType' => 'application/pdf', 'size' => 5120]],
    ];
}

function melOn(): void
{
    config(['platform-client.integrations.mel' => MR_URL]);
    Http::fake(['mel.internal/*' => fn () => value(test()->mel)]);
}

/**
 * The lead writes a Q3 report and submits it.
 */
function submitQ3(string $title = 'Q3 results'): Report
{
    test()->actingAsMember('lead-ngo', MR_PARTNER);
    test()->post(test()->organizationUrl('lead-ngo', 'shared-projects/'.test()->lead->id.'/reports'), ['title' => $title, 'category' => 'mel', 'reviewer' => 'owner', 'period_start' => '2026-07-01', 'period_end' => '2026-09-30'])->assertSessionHasNoErrors();
    $report = Report::query()->where('title', $title)->sole();
    test()->put(test()->organizationUrl('lead-ngo', 'shared-projects/'.test()->lead->id."/reports/{$report->id}"), ['content' => [
        'implementation' => ['activities_completed' => 'Drilled four wells'], 'narrative' => ['achievements' => 'Water reaches two villages'], 'financial' => ['lines' => [], 'note' => null],
    ]])->assertSessionHasNoErrors();
    test()->post(test()->organizationUrl('lead-ngo', 'shared-projects/'.test()->lead->id."/reports/{$report->id}/action"), ['action' => 'submit'])->assertSessionHasNoErrors();

    return $report->refresh();
}

function systemOf(Report $report): array
{
    return $report->refresh()->currentVersion->content['system'];
}

it('records nothing and asks nothing when this deployment does not connect to M&E', function () {
    $report = submitQ3();

    expect(systemOf($report)['mel'])->toBe([])
        ->and(systemOf($report)['melSource']['status'])->toBe('off')
        ->and($this->platform()->integrationTokens)->toBe([]);
});

it('records the published results M&E gives for the project and the report period, with their source', function () {
    melOn();
    $report = submitQ3();
    $system = systemOf($report);

    expect($system['melSource'])->toMatchArray(['application' => 'mel', 'status' => 'captured', 'error' => null, 'truncated' => false])
        ->and($system['melSource']['retrievedAt'])->not->toBeNull()
        ->and($system['melSource']['correlationId'])->not->toBeNull()
        ->and($system['mel'])->toHaveCount(1)
        ->and($system['mel'][0])->toMatchArray([
            'indicator' => 'OP1 · People with access to safe water', 'unit' => 'Juba', 'period' => '2026 Q3', 'unitLabel' => 'people', 'value' => '120.000000',
            'indicatorId' => '0198a3b2-0000-7000-8000-0000000000e2', 'periodStartsOn' => '2026-07-01', 'verifiedAt' => '2026-10-05T10:00:00+00:00', 'published' => true,
            'evidence' => [['name' => 'register.pdf', 'mimeType' => 'application/pdf', 'size' => 5120]],
        ]);

    // Read as the project's owner, by Projects itself: the submitting partner gets no M&E access.
    expect($this->platform()->integrationTokens)->toBe([['organization_id' => $this->organization('acme')->id, 'audience' => 'mel', 'acting_user' => null, 'application' => 'projects']]);
    Http::assertSent(fn (Request $request) => $request->url() === MR_URL."/projects/{$this->project->id}/verified-results?from=2026-07-01&to=2026-09-30");

    $this->actingAsMember('acme', ['projects.view', 'projects.reports.review']);
    $this->get($this->organizationUrl('acme', "projects/{$this->project->id}/reports/{$report->id}"))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('version.content.system.mel.0.value', '120.000000')->where('version.content.system.melSource.status', 'captured'));
});

it('keeps each submitted snapshot as it was, and takes a new one with each submission', function () {
    melOn();
    $report = submitQ3();
    $first = $report->currentVersion;

    // M&E corrects the value later: the submitted snapshot does not change.
    $this->mel = Http::response(['linked' => true, 'units' => 1, 'shared' => 1, 'truncated' => false, 'data' => [melRow('140.000000')]]);
    expect($first->refresh()->content['system']['mel'][0]['value'])->toBe('120.000000');

    $this->actingAsMember('acme', ['projects.view', 'projects.reports.review']);
    $this->post($this->organizationUrl('acme', "projects/{$this->project->id}/reports/{$report->id}/action"), ['action' => 'changes', 'comment' => 'Add the second village'])->assertSessionHasNoErrors();
    $submitter = User::query()->findOrFail($first->submitted_by);
    $this->actingAsMember('lead-ngo', MR_PARTNER, user: $submitter);
    $this->put($this->organizationUrl('lead-ngo', "shared-projects/{$this->lead->id}/reports/{$report->id}"), ['content' => [
        'implementation' => ['activities_completed' => 'Drilled four wells in two villages'], 'narrative' => [], 'financial' => ['lines' => [], 'note' => null],
    ]])->assertSessionHasNoErrors();
    $this->post($this->organizationUrl('lead-ngo', "shared-projects/{$this->lead->id}/reports/{$report->id}/action"), ['action' => 'submit'])->assertSessionHasNoErrors();

    expect($report->refresh()->currentVersion->number)->toBe(2)
        ->and(systemOf($report)['mel'][0]['value'])->toBe('140.000000')
        ->and($first->refresh()->content['system']['mel'][0]['value'])->toBe('120.000000');
});

it('says what M&E answered when there is nothing to record', function (array $answer, string $status) {
    melOn();
    $this->mel = Http::response($answer);

    expect(systemOf(submitQ3())['melSource']['status'])->toBe($status)->and(systemOf(Report::query()->sole())['mel'])->toBe([]);
})->with([
    'no unit linked to the project' => [['linked' => false, 'units' => 0, 'truncated' => false, 'data' => []], 'unlinked'],
    'nothing published for the period' => [['linked' => true, 'units' => 1, 'shared' => 1, 'truncated' => false, 'data' => []], 'no_results'],
    'linked, but no unit set to report here' => [['linked' => true, 'units' => 1, 'shared' => 0, 'truncated' => false, 'data' => []], 'not_shared'],
]);

it('submits the report all the same when M&E cannot answer, and records why, never as zero', function (mixed $setUp, string $status) {
    melOn();
    match (true) {
        $setUp === 'not_entitled' => $this->platform()->notEntitledTo = ['mel'],
        $setUp === 'expired' => $this->platform()->refuseIntegration = 'entitlement_expired',
        default => $this->mel = $setUp,
    };

    $report = submitQ3();

    expect($report->status)->toBe(Report::SUBMITTED)
        ->and(systemOf($report)['mel'])->toBe([])
        ->and(systemOf($report)['melSource']['status'])->toBe($status);
})->with([
    'no M&E subscription' => ['not_entitled', 'not_entitled'],
    'subscription expired' => ['expired', 'subscription_expired'],
    'M&E never set up' => [fn () => Http::response(['error' => 'not_provisioned'], 404), 'not_set_up'],
    'M&E refuses' => [fn () => Http::response(['error' => 'source_not_allowed'], 403), 'denied'],
    'M&E down' => [fn () => Http::response('', 503), 'unavailable'],
    'M&E too slow' => [fn () => throw new ConnectionException('cURL error 28: Operation timed out after 3000 milliseconds'), 'timeout'],
]);

it('shows the reason on the report page', function () {
    melOn();
    $this->mel = Http::response('', 503);
    $report = submitQ3();

    $this->actingAsMember('acme', ['projects.view', 'projects.reports.review']);
    $this->get($this->organizationUrl('acme', "projects/{$this->project->id}/reports/{$report->id}"))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('version.content.system.mel', [])->where('version.content.system.melSource.status', 'unavailable'));
});

it('keeps results recorded for a project that is completed later', function () {
    melOn();
    $report = submitQ3();
    inAcme(fn () => Project::query()->whereKey($this->project->id)->update(['status' => Project::COMPLETED]));

    expect(systemOf($report)['mel'][0]['value'])->toBe('120.000000');
    expect(Participant::query()->whereKey($this->lead->id)->exists())->toBeTrue();
});

it('asks nothing of M&E for a report without a period', function () {
    melOn();
    $this->actingAsMember('lead-ngo', MR_PARTNER);
    $this->post($this->organizationUrl('lead-ngo', "shared-projects/{$this->lead->id}/reports"), ['title' => 'Notes', 'category' => 'mel', 'reviewer' => 'owner'])->assertSessionHasNoErrors();
    $report = Report::query()->where('title', 'Notes')->sole();
    $this->put($this->organizationUrl('lead-ngo', "shared-projects/{$this->lead->id}/reports/{$report->id}"), ['content' => [
        'implementation' => ['activities_completed' => 'Wells'], 'narrative' => [], 'financial' => ['lines' => [], 'note' => null],
    ]])->assertSessionHasNoErrors();
    $this->post($this->organizationUrl('lead-ngo', "shared-projects/{$this->lead->id}/reports/{$report->id}/action"), ['action' => 'submit'])->assertSessionHasNoErrors();

    expect(systemOf($report)['melSource']['status'])->toBe('no_period')->and($this->platform()->integrationTokens)->toBe([]);
});

it('never replaces a frozen snapshot or adds a version when a submission is repeated', function () {
    melOn();
    $report = submitQ3();
    $this->mel = Http::response(['linked' => true, 'units' => 1, 'shared' => 1, 'truncated' => false, 'data' => [melRow('999.000000')]]);

    $this->post($this->organizationUrl('lead-ngo', "shared-projects/{$this->lead->id}/reports/{$report->id}/action"), ['action' => 'submit'])->assertSessionHasErrors('report');

    expect($report->refresh()->versions()->count())->toBe(1)
        ->and(systemOf($report)['mel'][0]['value'])->toBe('120.000000');
});

/*
| Who sees the recorded results: the project owner's members only.
*/

it('shows the results to the owner only, not to the implementer or the donor (donor and implementer)', function () {
    melOn();
    $donor = acceptProjectInvitation(inviteToProject($this->project, 'global-fund', 'donor', ['view', 'donor_reports.view']), 'global-fund');
    $report = submitQ3();

    // The implementer that submitted it: the report, without the owner's M&E data.
    $this->get($this->organizationUrl('lead-ngo', "shared-projects/{$this->lead->id}/reports/{$report->id}"))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('version.content.system.mel', [])->missing('version.content.system.melSource')
            ->where('version.content.implementation.activities_completed', 'Drilled four wells'));

    // The owner reviews, approves and publishes it.
    $this->actingAsMember('acme', ['projects.view', 'projects.reports.review', 'projects.reports.publish']);
    $this->get($this->organizationUrl('acme', "projects/{$this->project->id}/reports/{$report->id}"))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('version.content.system.mel.0.value', '120.000000'));
    $this->post($this->organizationUrl('acme', "projects/{$this->project->id}/reports/{$report->id}/action"), ['action' => 'approve'])->assertSessionHasNoErrors();
    $this->post($this->organizationUrl('acme', "projects/{$this->project->id}/reports/{$report->id}/action"), ['action' => 'publish'])->assertSessionHasNoErrors();
    expect($report->refresh()->status)->toBe(Report::PUBLISHED);

    // The donor reads the published report: still without them.
    $this->actingAsMember('global-fund', MR_PARTNER);
    $this->get($this->organizationUrl('global-fund', "shared-projects/{$donor->id}/reports/{$report->id}"))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('version.content.system.mel', [])->missing('version.content.system.melSource'));

    // They are still recorded, unchanged.
    expect(systemOf($report)['mel'][0]['value'])->toBe('120.000000');
});

it('keeps the results from the lead, the sub-partner and the donor (donor, lead and sub-partner)', function () {
    melOn();
    $lead = acceptProjectInvitation(inviteToProject($this->project, 'lead2', 'lead_implementer', ['view', 'partners.manage', 'reports.submit', 'reports.review', 'reports.publish']), 'lead2');
    $donor = acceptProjectInvitation(inviteToProject($this->project, 'global-fund', 'donor', ['view', 'donor_reports.view']), 'global-fund');
    $this->actingAsMember('lead2', COLLAB_MANAGER);
    $this->post($this->organizationUrl('lead2', "shared-projects/{$lead->id}/invitations"), [
        'organization_id' => $this->organization('field-ngo')->id, 'relationship' => 'sub_implementer', 'capabilities' => ['view', 'reports.submit'],
    ])->assertSessionHasNoErrors();
    $sub = acceptProjectInvitation(Invitation::query()->where('invited_organization_id', $this->organization('field-ngo')->id)->sole(), 'field-ngo');

    // The sub-partner writes to its lead.
    $this->actingAsMember('field-ngo', MR_PARTNER);
    $this->post($this->organizationUrl('field-ngo', "shared-projects/{$sub->id}/reports"), ['title' => 'Field Q3', 'category' => 'mel', 'period_start' => '2026-07-01', 'period_end' => '2026-09-30'])->assertSessionHasNoErrors();
    $report = Report::query()->where('title', 'Field Q3')->sole();
    $this->put($this->organizationUrl('field-ngo', "shared-projects/{$sub->id}/reports/{$report->id}"), ['content' => [
        'implementation' => ['activities_completed' => 'Two wells'], 'narrative' => [], 'financial' => ['lines' => [], 'note' => null],
    ]])->assertSessionHasNoErrors();
    $this->post($this->organizationUrl('field-ngo', "shared-projects/{$sub->id}/reports/{$report->id}/action"), ['action' => 'submit'])->assertSessionHasNoErrors();
    expect($report->refresh()->reviewer_participant_id)->toBe($lead->id)
        ->and(systemOf($report)['melSource']['status'])->toBe('captured');

    $this->get($this->organizationUrl('field-ngo', "shared-projects/{$sub->id}/reports/{$report->id}"))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('version.content.system.mel', []));

    // The lead reviews, approves and publishes: never sees them.
    $this->actingAsMember('lead2', MR_PARTNER);
    $this->get($this->organizationUrl('lead2', "shared-projects/{$lead->id}/reports/{$report->id}"))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('version.content.system.mel', [])->missing('version.content.system.melSource'));
    $this->post($this->organizationUrl('lead2', "shared-projects/{$lead->id}/reports/{$report->id}/action"), ['action' => 'approve'])->assertSessionHasNoErrors();
    $this->post($this->organizationUrl('lead2', "shared-projects/{$lead->id}/reports/{$report->id}/action"), ['action' => 'publish'])->assertSessionHasNoErrors();

    $this->actingAsMember('global-fund', MR_PARTNER);
    $this->get($this->organizationUrl('global-fund', "shared-projects/{$donor->id}/reports/{$report->id}"))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('version.content.system.mel', []));

    // The owner, once it is published, sees them.
    $this->actingAsMember('acme', ['projects.view']);
    $this->get($this->organizationUrl('acme', "projects/{$this->project->id}/reports/{$report->id}"))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('version.content.system.mel.0.value', '120.000000'));
});
