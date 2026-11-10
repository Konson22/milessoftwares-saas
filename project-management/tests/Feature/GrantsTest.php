<?php

use App\Enums\GrantReportStatus;
use App\Enums\GrantStatus;
use App\Models\AuditEvent;
use App\Models\Funder;
use App\Models\Grant;
use App\Models\GrantAllocation;
use App\Models\GrantAmendment;
use App\Models\Project;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Grants and donors: funders, grants from opportunity to closeout, awarded
| amounts changed only by amendment, allocations to several projects (with
| an explicit exchange rate across currencies) that never exceed the grant,
| the payment schedule, reports owed to the funder, and closeout.
*/

const GRANTS_MANAGER = ['projects.view', 'projects.manage', 'projects.finance.view', 'grants.view', 'grants.manage'];

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 09:00'));
    $this->organization('acme')->forceFill(['currency' => 'SSP', 'timezone' => 'Africa/Juba'])->save();
});

function grantsUrl(string $path = ''): string
{
    return test()->organizationUrl('acme', $path);
}

function aFunder(string $name = 'ECHO'): Funder
{
    test()->post(grantsUrl('funders'), ['name' => $name, 'kind' => 'institutional', 'country_code' => 'be'])->assertSessionHasNoErrors();

    return test()->asTenant('acme', fn () => Funder::query()->where('name', $name)->firstOrFail());
}

/**
 * @param  array<string, mixed>  $details
 */
function aGrant(array $details = []): Grant
{
    $funder = test()->asTenant('acme', fn () => Funder::query()->first()) ?? aFunder();
    $details = [
        'funder_id' => $funder->id,
        'reference' => 'echo/2026/041',
        'title' => 'Emergency WASH in Juba',
        'currency' => 'usd',
        'amount' => '120000',
        'restricted' => true,
        'status' => 'applied',
        ...$details,
    ];
    test()->post(grantsUrl('grants'), $details)->assertSessionHasNoErrors();

    return test()->asTenant('acme', fn () => Grant::query()->where('reference', mb_strtoupper($details['reference']))->firstOrFail());
}

function awarded(string $amount = '100000'): Grant
{
    $grant = aGrant();
    test()->post(grantsUrl("grants/{$grant->id}/award"), ['amount' => $amount, 'on' => '2026-11-01', 'start_on' => '2026-11-01', 'end_on' => '2027-10-31'])->assertSessionHasNoErrors();

    return $grant->fresh() ?? $grant;
}

function aProject(string $code, ?string $currency = null): Project
{
    test()->post(grantsUrl('projects'), ['code' => $code, 'name' => "Project {$code}", 'status' => 'active'])->assertSessionHasNoErrors();
    $project = test()->asTenant('acme', fn () => Project::query()->where('code', $code)->firstOrFail());
    if ($currency !== null) {
        $project->forceFill(['currency' => $currency])->save();
    }

    return $project;
}

it('keeps a directory of funders', function () {
    $this->actingAsMember('acme', GRANTS_MANAGER);
    $funder = aFunder('ECHO');

    expect($funder->kind->label())->toBe('Institutional donor')->and($funder->country_code)->toBe('BE');
    $this->post(grantsUrl('funders'), ['name' => 'ECHO', 'kind' => 'institutional'])->assertSessionHasErrors('name');

    $this->put(grantsUrl("funders/{$funder->id}"), ['name' => 'ECHO (EU)', 'kind' => 'multilateral', 'active' => false])->assertSessionHasNoErrors();
    expect($funder->fresh()?->name)->toBe('ECHO (EU)')->and($funder->fresh()?->active)->toBeFalse();

    $this->get(grantsUrl('funders'))->assertInertia(fn (Assert $page) => $page
        ->component('portal/funding/funders/index')
        ->where('funders.0.name', 'ECHO (EU)'));
});

it('takes a grant from application to award, then changes its amount only by amendment', function () {
    $this->actingAsMember('acme', GRANTS_MANAGER);
    $grant = aGrant();

    expect($grant->reference)->toBe('ECHO/2026/041')
        ->and($grant->status)->toBe(GrantStatus::Applied)
        ->and($grant->currency)->toBe('USD')
        ->and($grant->amount_minor)->toBe(12_000_000);

    // Before award the request can change freely.
    $this->put(grantsUrl("grants/{$grant->id}"), ['funder_id' => $grant->funder_id, 'reference' => $grant->reference, 'title' => $grant->title, 'currency' => 'USD', 'amount' => '110000'])->assertSessionHasNoErrors();
    expect($grant->fresh()?->amount_minor)->toBe(11_000_000);

    $this->post(grantsUrl("grants/{$grant->id}/award"), ['amount' => '100000', 'on' => '2026-11-01', 'start_on' => '2026-11-01', 'end_on' => '2027-10-31'])->assertSessionHasNoErrors();
    $grant->refresh();
    expect($grant->status)->toBe(GrantStatus::Active)->and($grant->amount_minor)->toBe(10_000_000);

    // Editing an awarded grant leaves its amount alone.
    $this->put(grantsUrl("grants/{$grant->id}"), ['funder_id' => $grant->funder_id, 'reference' => $grant->reference, 'title' => 'Renamed', 'currency' => 'USD', 'amount' => '999999'])->assertSessionHasNoErrors();
    expect($grant->fresh()?->amount_minor)->toBe(10_000_000)->and($grant->fresh()?->title)->toBe('Renamed');

    $this->post(grantsUrl("grants/{$grant->id}/amendments"), ['amount' => '125000', 'end_on' => '2028-03-31', 'effective_on' => '2026-11-04', 'reason' => 'Cost extension for the rainy season'])->assertSessionHasNoErrors();
    $grant->refresh();
    $amendment = $this->asTenant('acme', fn () => GrantAmendment::query()->firstOrFail());
    expect($grant->amount_minor)->toBe(12_500_000)
        ->and($grant->end_on?->toDateString())->toBe('2028-03-31')
        ->and($amendment->number)->toBe(1)
        ->and($amendment->amount_before_minor)->toBe(10_000_000)
        ->and($amendment->end_before?->toDateString())->toBe('2027-10-31')
        ->and(fn () => $amendment->update(['reason' => 'x']))->toThrow(LogicException::class)
        ->and(fn () => $amendment->delete())->toThrow(LogicException::class)
        ->and(AuditEvent::query()->pluck('action')->all())->toContain('grant.created', 'grant.awarded', 'grant.amended');
});

it('allocates a grant across projects, never beyond what it holds', function () {
    $this->actingAsMember('acme', GRANTS_MANAGER);
    $grant = awarded('100000');
    $wash = aProject('WASH', 'USD');
    $health = aProject('HLTH', 'USD');

    $this->post(grantsUrl("grants/{$grant->id}/allocations"), ['project_id' => $wash->id, 'amount' => '60000'])->assertSessionHasNoErrors();
    $this->post(grantsUrl("grants/{$grant->id}/allocations"), ['project_id' => $health->id, 'amount' => '40000.01'])->assertSessionHasErrors('amount');
    $this->post(grantsUrl("grants/{$grant->id}/allocations"), ['project_id' => $health->id, 'amount' => '40000'])->assertSessionHasNoErrors();

    expect($grant->allocatedMinor())->toBe(10_000_000);

    // Lowering one frees room; the amendment floor follows allocations.
    $allocation = $this->asTenant('acme', fn () => GrantAllocation::query()->where('project_id', $wash->id)->firstOrFail());
    $this->put(grantsUrl("grants/{$grant->id}/allocations/{$allocation->id}"), ['amount' => '50000'])->assertSessionHasNoErrors();
    $this->post(grantsUrl("grants/{$grant->id}/amendments"), ['amount' => '80000', 'effective_on' => '2026-11-04', 'reason' => 'Cut'])->assertSessionHasErrors('amount');
    $this->post(grantsUrl("grants/{$grant->id}/amendments"), ['amount' => '90000', 'end_on' => '2027-10-31', 'effective_on' => '2026-11-04', 'reason' => 'Cut'])->assertSessionHasNoErrors();

    // A second donor on the same project: no duplicate project.
    $other = aGrant(['reference' => 'USAID-77', 'status' => 'applied']);
    $this->post(grantsUrl("grants/{$other->id}/award"), ['amount' => '20000', 'on' => '2026-11-02'])->assertSessionHasNoErrors();
    $this->post(grantsUrl("grants/{$other->id}/allocations"), ['project_id' => $wash->id, 'amount' => '20000'])->assertSessionHasNoErrors();

    expect($this->asTenant('acme', fn () => (int) $wash->grantAllocations()->sum('project_amount_minor')))->toBe(7_000_000);
});

it('converts across currencies at the rate given, exactly', function () {
    $this->actingAsMember('acme', GRANTS_MANAGER);
    $grant = awarded('100000');
    $ssp = aProject('WASH', 'SSP');
    $yen = aProject('JPN', 'JPY');

    $this->post(grantsUrl("grants/{$grant->id}/allocations"), ['project_id' => $ssp->id, 'amount' => '1000.01'])->assertSessionHasErrors('exchange_rate');
    $this->post(grantsUrl("grants/{$grant->id}/allocations"), ['project_id' => $ssp->id, 'amount' => '1000.01', 'exchange_rate' => '2450.5'])->assertSessionHasNoErrors();
    $this->post(grantsUrl("grants/{$grant->id}/allocations"), ['project_id' => $yen->id, 'amount' => '10.55', 'exchange_rate' => '151.37'])->assertSessionHasNoErrors();

    $allocations = $this->asTenant('acme', fn () => GrantAllocation::query()->get()->keyBy('project_id'));
    // 1000.01 × 2450.5 = 2,450,524.5050 SSP → 245,052,451 minor (half up).
    expect($allocations[$ssp->id]->project_amount_minor)->toBe(245_052_451)
        ->and($allocations[$ssp->id]->exchange_rate)->toBe('2450.50000000')
        // 10.55 × 151.37 = 1,596.9535 JPY → 1,597 (no minor unit).
        ->and($allocations[$yen->id]->project_amount_minor)->toBe(1_597)
        ->and(Money::convert(1, 'USD', 'SSP', '0.5'))->toBe(1)
        ->and(fn () => Money::convert(100, 'USD', 'SSP', '0'))->toThrow(InvalidArgumentException::class);
});

it('schedules payments up to the grant, and keeps received ones', function () {
    $this->actingAsMember('acme', GRANTS_MANAGER);
    $grant = awarded('100000');

    $this->post(grantsUrl("grants/{$grant->id}/disbursements"), ['due_on' => '2026-11-15', 'amount' => '60000'])->assertSessionHasNoErrors();
    $this->post(grantsUrl("grants/{$grant->id}/disbursements"), ['due_on' => '2027-05-15', 'amount' => '50000'])->assertSessionHasErrors('amount');
    $this->post(grantsUrl("grants/{$grant->id}/disbursements"), ['due_on' => '2027-05-15', 'amount' => '40000'])->assertSessionHasNoErrors();

    $first = $grant->disbursements()->firstOrFail();
    $this->post(grantsUrl("grants/{$grant->id}/disbursements/{$first->id}/receive"), ['on' => '2026-11-20'])->assertSessionHasErrors('received_on');
    $this->post(grantsUrl("grants/{$grant->id}/disbursements/{$first->id}/receive"), ['on' => '2026-11-03'])->assertSessionHasNoErrors();
    $this->delete(grantsUrl("grants/{$grant->id}/disbursements/{$first->id}"))->assertSessionHasErrors('disbursement');

    expect($grant->receivedMinor())->toBe(6_000_000)->and($grant->scheduledMinor())->toBe(10_000_000);

    // Finance is told, in the grant's currency: it converts at its own rate.
    $received = $this->financeEvents('acme', 'finance.grant.received');
    expect($received)->toHaveCount(1)
        ->and($received[0]['payload'])->toMatchArray(['reference' => "grant-payment:{$first->id}", 'grant_reference' => $grant->reference, 'received_on' => '2026-11-03', 'currency' => $grant->currency, 'amount_minor' => 6_000_000, 'method' => 'bank_transfer']);
});

it('closes a grant only when no report is owed', function () {
    $this->actingAsMember('acme', GRANTS_MANAGER);
    $grant = awarded();

    $this->post(grantsUrl("grants/{$grant->id}/reports"), ['title' => 'Final report', 'kind' => 'combined', 'due_on' => '2026-12-31'])->assertSessionHasNoErrors();
    $report = $grant->reports()->firstOrFail();

    $this->post(grantsUrl("grants/{$grant->id}/close"), ['on' => '2026-11-04'])->assertSessionHasErrors('grant');

    $this->post(grantsUrl("grants/{$grant->id}/reports/{$report->id}/status"), ['status' => 'accepted', 'on' => '2026-11-04'])->assertSessionHasErrors('status');
    $this->post(grantsUrl("grants/{$grant->id}/reports/{$report->id}/status"), ['status' => 'submitted', 'on' => '2026-11-04'])->assertSessionHasNoErrors();
    $this->post(grantsUrl("grants/{$grant->id}/reports/{$report->id}/status"), ['status' => 'revision_requested', 'on' => '2026-11-04'])->assertSessionHasNoErrors();
    $this->post(grantsUrl("grants/{$grant->id}/close"), ['on' => '2026-11-04'])->assertSessionHasErrors('grant');

    $this->post(grantsUrl("grants/{$grant->id}/reports/{$report->id}/status"), ['status' => 'submitted', 'on' => '2026-11-04'])->assertSessionHasNoErrors();
    $this->delete(grantsUrl("grants/{$grant->id}/reports/{$report->id}"))->assertSessionHasErrors('report');
    $this->post(grantsUrl("grants/{$grant->id}/close"), ['on' => '2026-11-04'])->assertSessionHasNoErrors();

    $grant->refresh();
    expect($grant->status)->toBe(GrantStatus::Closed)
        ->and($report->fresh()?->status)->toBe(GrantReportStatus::Submitted);

    // A closed grant takes no new money.
    $project = aProject('WASH', 'USD');
    $this->post(grantsUrl("grants/{$grant->id}/allocations"), ['project_id' => $project->id, 'amount' => '10'])->assertSessionHasErrors('grant');
});

it('shows the grant, its pipeline, and the funding on a project budget', function () {
    $this->actingAsMember('acme', GRANTS_MANAGER);
    $grant = awarded('100000');
    $project = aProject('WASH', 'USD');
    $this->post(grantsUrl("grants/{$grant->id}/allocations"), ['project_id' => $project->id, 'amount' => '25000'])->assertSessionHasNoErrors();
    $this->post(grantsUrl("grants/{$grant->id}/reports"), ['title' => 'Interim report', 'kind' => 'narrative', 'due_on' => '2026-11-01'])->assertSessionHasNoErrors();

    $this->get(grantsUrl('grants'))->assertInertia(fn (Assert $page) => $page
        ->component('portal/funding/grants/index')
        ->where('grants.0.reference', 'ECHO/2026/041')
        ->where('grants.0.allocatedMinor', 2_500_000)
        ->where('activeTotals.0', ['currency' => 'USD', 'amountMinor' => 10_000_000, 'grants' => 1])
        ->where('reportsDue.0.overdue', true));

    $this->get(grantsUrl("grants/{$grant->id}"))->assertInertia(fn (Assert $page) => $page
        ->component('portal/funding/grants/show')
        ->where('totals.availableMinor', 7_500_000)
        ->where('allocations.0.project', 'WASH · Project WASH')
        ->has('projects', 0));

    $this->get(grantsUrl("projects/{$project->id}/budget"))->assertInertia(fn (Assert $page) => $page
        ->where('funding.0.reference', 'ECHO/2026/041')
        ->where('funding.0.projectAmountMinor', 2_500_000));
});

it('lets project managers look at grants but not change them', function () {
    $this->actingAsMember('acme', GRANTS_MANAGER);
    $grant = aGrant();
    $funder = $grant->funder;

    $viewer = $this->member('acme', ['projects.view', 'grants.view']);
    $this->actingAs($viewer);
    $this->get(grantsUrl("grants/{$grant->id}"))->assertOk();
    $this->post(grantsUrl("grants/{$grant->id}/award"), ['amount' => '1', 'on' => '2026-11-01'])->assertForbidden();
    $this->put(grantsUrl("funders/{$funder->id}"), ['name' => 'X', 'kind' => 'other'])->assertForbidden();

    $staff = $this->member('acme', ['projects.view']);
    $this->actingAs($staff);
    $this->get(grantsUrl('grants'))->assertForbidden();
    $this->get(grantsUrl('funders'))->assertForbidden();
});

it('keeps each organization\'s grants to itself', function () {
    $this->actingAsMember('acme', GRANTS_MANAGER);
    $grant = awarded();

    $outsider = $this->member('globex', GRANTS_MANAGER);
    $this->actingAs($outsider);
    $theirs = $this->asTenant('globex', function () use ($outsider) {
        $project = Project::query()->create(['code' => 'GLX', 'name' => 'Theirs', 'status' => 'active', 'currency' => 'USD', 'created_by' => $outsider->id]);

        return $project;
    });

    $this->get($this->organizationUrl('globex', "grants/{$grant->id}"))->assertNotFound();
    $this->post($this->organizationUrl('globex', "grants/{$grant->id}/allocations"), ['project_id' => $theirs->id, 'amount' => '1'])->assertNotFound();

    // Acme cannot allocate its grant to Globex's project either.
    $this->actingAs(User::query()->whereKeyNot($outsider->id)->firstOrFail());
    $this->post(grantsUrl("grants/{$grant->id}/allocations"), ['project_id' => $theirs->id, 'amount' => '1'])->assertSessionHasErrors('project_id');
});
