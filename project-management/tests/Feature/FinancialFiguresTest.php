<?php

use App\Collaboration\Models\Report;
use App\Collaboration\ReportContent;
use App\Enums\BudgetVersionStatus;
use App\Enums\CostCategory;
use App\Enums\ExpenseStatus;
use App\Models\BudgetVersion;
use App\Models\Person;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\ProjectFinance;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
| One set of numbers (docs/finance/metric-definitions.md): the same project
| shows the same approved budget, commitments and payments wherever it is
| read, never the estimated staff cost outside the owner, and says whether
| Finance posted the payments.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 09:00'));
    Notification::fake();
});

/**
 * Approved 10,000.00; 1,500.00 paid in two payments; 700.00 approved and
 * unpaid; 300.00 requested; 12.50 of staff time at a confidential rate.
 *
 * @return array{0: Project, 1: list<string>}
 */
function ffProject(): array
{
    $project = collabProject();
    $paid = test()->asTenant('acme', function () use ($project) {
        $by = User::query()->firstOrFail()->id;
        $version = tap(tap(new BudgetVersion)->forceFill(['project_id' => $project->id, 'number' => 1, 'status' => BudgetVersionStatus::Approved, 'total_minor' => 1_000_000, 'created_by' => $by]))->save();
        $version->lines()->create(['project_id' => $project->id, 'category' => CostCategory::Materials, 'amount_minor' => 900_000]);
        $version->lines()->create(['project_id' => $project->id, 'category' => CostCategory::Labour, 'amount_minor' => 100_000]);
        $expense = fn (int $amount, ExpenseStatus $status) => $project->expenses()->create(['category' => CostCategory::Materials, 'description' => 'Pipes', 'amount_minor' => $amount, 'status' => $status, 'direct' => true, 'spent_on' => '2026-11-02', 'recorded_by' => $by]);
        $paid = [$expense(100_000, ExpenseStatus::Paid)->id, $expense(50_000, ExpenseStatus::Paid)->id];
        $expense(70_000, ExpenseStatus::Approved);
        $expense(30_000, ExpenseStatus::Requested);
        $person = Person::query()->create(['name' => 'Engineer', 'hourly_cost_minor' => 1_250]);
        TimeEntry::query()->create(['project_id' => $project->id, 'person_id' => $person->id, 'worked_on' => '2026-11-03', 'minutes' => 60, 'cost_minor' => 1_250, 'created_by' => $by]);

        return $paid;
    });

    return [$project, $paid];
}

function ffOutbox(string $expenseId, string $status): void
{
    DB::table('platform_outbox')->insert([
        'id' => (string) Str::uuid(), 'organization_id' => test()->organization('acme')->id, 'audience' => 'finance',
        'event_type' => 'finance.project_expense.paid', 'schema_version' => 1, 'source_transaction_id' => "expense:{$expenseId}:paid",
        'payload' => '{}', 'occurred_at' => now(), 'status' => $status, 'attempts' => 1, 'last_error' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('defines each figure once: committed, paid and estimated staff time kept apart', function () {
    [$project] = ffProject();

    $figures = $this->asTenant('acme', fn () => ProjectFinance::for($project));

    expect($figures)->toMatchArray([
        'currency' => 'USD',
        'approvedBudgetMinor' => 1_000_000,
        'budgetBasis' => 'approved',
        'committedMinor' => 70_000,     // approved, unpaid; a request is not a commitment
        'paidMinor' => 150_000,
        'personnelEstimateMinor' => 1_250,
        'availableMinor' => 1_000_000 - 70_000 - 150_000 - 1_250,
    ])->and($figures['provenance'])->toMatchArray(['source' => 'projects', 'paidStatus' => 'recorded', 'unpostedPayments' => 2, 'asOf' => '2026-11-16']);

    // What may leave the organization has no staff cost in it.
    expect(ProjectFinance::shareable($figures))->not->toHaveKey('personnelEstimateMinor')->not->toHaveKey('availableMinor');
});

it('calls payments posted only when Finance took every one of them', function () {
    [$project, $paid] = ffProject();

    ffOutbox($paid[0], 'delivered');
    ffOutbox($paid[1], 'failed');
    expect($this->asTenant('acme', fn () => ProjectFinance::for($project))['provenance'])->toMatchArray(['paidStatus' => 'recorded', 'unpostedPayments' => 1]);

    DB::table('platform_outbox')->where('source_transaction_id', "expense:{$paid[1]}:paid")->update(['status' => 'duplicate']);
    expect($this->asTenant('acme', fn () => ProjectFinance::for($project))['provenance'])->toMatchArray(['paidStatus' => 'posted', 'unpostedPayments' => 0]);
});

it('shows a participant and a partner report the same figures as the owner, without staff cost', function () {
    [$project] = ffProject();
    $owner = $this->asTenant('acme', fn () => ProjectFinance::for($project));
    $donor = acceptProjectInvitation(inviteToProject($project, 'global-fund', 'donor', ['view', 'budget.view', 'reports.submit']), 'global-fund');

    $this->actingAsMember('global-fund', ['projects.view', 'projects.partners.manage', 'projects.reports.prepare', 'projects.reports.review']);
    $response = $this->get($this->organizationUrl('global-fund', "shared-projects/{$donor->id}"))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('project.budget.approvedMinor', $owner['approvedBudgetMinor'])
        ->where('project.budget.paidMinor', $owner['paidMinor'])
        ->where('project.budget.spentMinor', $owner['paidMinor'])
        ->where('project.budget.committedMinor', $owner['committedMinor'])
        ->where('project.budget.currency', 'USD')
        ->where('project.budget.provenance.paidStatus', 'recorded')
        ->missing('project.budget.personnelEstimateMinor'));
    // Neither the staff cost nor the hourly rate travels.
    $response->assertDontSee('1250')->assertDontSee('12.50')->assertDontSee('hourly');

    // A report's recorded snapshot: the same figures, with its basis.
    $this->post($this->organizationUrl('global-fund', "shared-projects/{$donor->id}/reports"), ['title' => 'November', 'category' => 'monthly'])->assertSessionHasNoErrors();
    $snapshot = ReportContent::snapshot(Report::query()->sole(), true)['financial'];
    expect($snapshot)->toMatchArray([
        'approvedMinor' => $owner['approvedBudgetMinor'],
        'spentMinor' => $owner['paidMinor'],
        'committedMinor' => $owner['committedMinor'],
        'varianceMinor' => $owner['approvedBudgetMinor'] - $owner['paidMinor'],
        'basis' => 'paid',
        'currency' => 'USD',
    ])->and($snapshot['provenance']['paidStatus'])->toBe('recorded');
});

it('labels a budget that is not approved for what it is, on the owner\'s pages', function () {
    $project = collabProject();
    $this->asTenant('acme', fn () => $project->forceFill(['budget_minor' => 250_000])->save());

    $this->actingAsMember('acme', ['projects.view', 'projects.finance.view']);
    $this->get($this->organizationUrl('acme', 'projects'))->assertInertia(fn (Assert $page) => $page
        ->where('projects.0.budgetMinor', 250_000)->where('projects.0.budgetBasis', 'estimate'));
    $this->get($this->organizationUrl('acme', "projects/{$project->id}"))->assertInertia(fn (Assert $page) => $page
        ->where('metrics.budgetBasis', 'estimate'));
    expect($this->asTenant('acme', fn () => ProjectFinance::for($project)))->toMatchArray(['approvedBudgetMinor' => null, 'availableMinor' => null]);

    // Without finance access: no figures and no basis.
    $this->actingAsMember('acme', ['projects.view']);
    $this->get($this->organizationUrl('acme', "projects/{$project->id}"))->assertInertia(fn (Assert $page) => $page
        ->where('metrics.budgetMinor', null)->where('metrics.budgetBasis', null));
});

it('keeps another organization\'s payments out of the posting check', function () {
    [$project, $paid] = ffProject();
    // A delivered row for the same id in another organization's outbox does not count.
    DB::table('platform_outbox')->insert([
        'id' => (string) Str::uuid(), 'organization_id' => $this->organization('globex')->id, 'audience' => 'finance',
        'event_type' => 'finance.project_expense.paid', 'schema_version' => 1, 'source_transaction_id' => "expense:{$paid[0]}:paid",
        'payload' => '{}', 'occurred_at' => now(), 'status' => 'delivered', 'attempts' => 1, 'last_error' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect($this->asTenant('acme', fn () => ProjectFinance::for($project))['provenance']['unpostedPayments'])->toBe(2);
});
