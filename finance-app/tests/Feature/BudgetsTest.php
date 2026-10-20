<?php

use App\Finance\Statements;
use App\Models\Account;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\DimensionValue;
use App\Models\FiscalYear;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

const PLANNER = [
    'finance.accounts.view', 'finance.accounts.manage', 'finance.journals.view', 'finance.journals.post',
    'finance.reports.view', 'finance.periods.manage', 'finance.budgets.manage',
];

beforeEach(function () {
    $this->platform()->organization('acme');
    $this->publishEventKeys();
    $this->setUpFinance('acme');
    $this->actingAsMember('acme', PLANNER);

    $this->post(budgetUrl('periods/years'), ['starts_on' => '2025-01-01', 'months' => 12])->assertSessionHasNoErrors();
    $this->post(budgetUrl('periods/years'), ['starts_on' => '2026-01-01', 'months' => 12])->assertSessionHasNoErrors();
});

function budgetUrl(string $path): string
{
    return test()->workspaceUrl('acme', $path);
}

function budgetAccount(string $code): Account
{
    return test()->asTenant('acme', fn () => Account::query()->where('code', $code)->sole());
}

function budgetYear(string $starts): string
{
    return test()->asTenant('acme', fn () => FiscalYear::query()->whereDate('starts_on', $starts)->sole()->id);
}

function budgetEntry(string $date, string $debit, string $credit, string $amount, ?string $costCentre = null): void
{
    $line = ['account_id' => budgetAccount($debit)->id, 'debit' => $amount];
    $other = ['account_id' => budgetAccount($credit)->id, 'credit' => $amount];
    // The cost centre goes on the income or spending line.
    if ($costCentre !== null) {
        if (in_array($debit, ['6000', '6100', '6900'], true)) {
            $line['cost_centre_id'] = $costCentre;
        } else {
            $other['cost_centre_id'] = $costCentre;
        }
    }
    test()->post(budgetUrl('journals'), ['entry_date' => $date, 'memo' => 'Entry', 'lines' => [$line, $other]])->assertSessionHasNoErrors();
}

function budgetNamed(string $name): Budget
{
    return test()->asTenant('acme', fn () => Budget::query()->where('name', $name)->sole());
}

/**
 * @return array<string, int> "code/period" => amount
 */
function budgetLines(Budget $budget): array
{
    return test()->asTenant('acme', fn () => BudgetLine::query()->with('account')->where('budget_id', $budget->id)->get()
        ->mapWithKeys(fn (BudgetLine $line) => ["{$line->account->code}/{$line->period}" => $line->amount_minor])
        ->sortKeys()
        ->all());
}

function costCentre(string $code): DimensionValue
{
    test()->post(budgetUrl('dimensions'), ['kind' => 'cost_centre', 'code' => $code, 'name' => $code])->assertSessionHasNoErrors();

    return test()->asTenant('acme', fn () => DimensionValue::query()->where('code', $code)->sole());
}

it('copies last year\'s actuals month by month, changed by a percentage', function () {
    budgetEntry('2025-03-10', '1000', '4000', '1000');
    budgetEntry('2025-03-20', '6000', '1000', '200.05');
    budgetEntry('2025-07-01', '1000', '4000', '50');

    $this->post(budgetUrl('budgets'), ['fiscal_year_id' => budgetYear('2026-01-01'), 'name' => 'Main', 'copy' => 'actuals', 'uplift' => '10'])
        ->assertRedirect();
    $budget = budgetNamed('Main');

    // 200.05 × 1.1 = 220.055 → 220.06 (half away from zero).
    expect(budgetLines($budget))->toBe(['4000/3' => 110000, '4000/7' => 5500, '6000/3' => 22006]);

    $this->post(budgetUrl('budgets'), ['fiscal_year_id' => budgetYear('2026-01-01'), 'name' => 'Main'])->assertSessionHasErrors('name');
    $this->post(budgetUrl('budgets'), ['fiscal_year_id' => budgetYear('2025-01-01'), 'name' => 'First', 'copy' => 'actuals'])->assertSessionHasErrors('copy');
    $this->post(budgetUrl('budgets'), ['fiscal_year_id' => budgetYear('2025-01-01'), 'name' => 'Empty'])->assertSessionHasNoErrors();
    expect(budgetLines(budgetNamed('Empty')))->toBe([]);
});

it('saves the grid, keeping only amounts on revenue and expense accounts', function () {
    $this->post(budgetUrl('budgets'), ['fiscal_year_id' => budgetYear('2026-01-01'), 'name' => 'Main'])->assertRedirect();
    $budget = budgetNamed('Main');

    $this->get(budgetUrl("budgets/{$budget->id}"))->assertInertia(fn (Assert $page) => $page
        ->component('budgets/show')
        ->has('periods', 12)
        ->where('periods.0.label', 'Jan 2026')
        ->where('accounts', fn ($accounts) => collect($accounts)->every(fn ($account) => in_array($account['type'], ['revenue', 'expense'], true))));

    $sales = budgetAccount('4000')->id;
    $rent = budgetAccount('6000')->id;
    $this->put(budgetUrl("budgets/{$budget->id}"), [
        'name' => 'Main 2026',
        'amounts' => [
            $sales => array_combine(range(1, 12), array_fill(0, 12, '500')),
            $rent => [1 => '', 3 => '250.50', 4 => '0', 5 => '-10'],
        ],
    ])->assertSessionHasNoErrors();

    $lines = budgetLines($budget);
    expect($lines)->toHaveCount(14)
        ->and($lines['4000/12'])->toBe(50000)
        ->and($lines['6000/3'])->toBe(25050)
        ->and($lines['6000/5'])->toBe(-1000)
        ->and($lines)->not->toHaveKey('6000/4')
        ->and(budgetNamed('Main 2026')->id)->toBe($budget->id);

    // Saving replaces the grid.
    $this->put(budgetUrl("budgets/{$budget->id}"), ['name' => 'Main 2026', 'amounts' => [$rent => [2 => '1']]])->assertSessionHasNoErrors();
    expect(budgetLines($budget))->toBe(['6000/2' => 100]);

    $this->put(budgetUrl("budgets/{$budget->id}"), ['name' => 'Main 2026', 'amounts' => [budgetAccount('1000')->id => [1 => '5']]])->assertSessionHasErrors('amounts');
    $this->put(budgetUrl("budgets/{$budget->id}"), ['name' => 'Main 2026', 'amounts' => [$rent => [1 => '1.234']]])->assertSessionHasErrors();
    expect(budgetLines($budget))->toBe(['6000/2' => 100]);
});

it('compares with actuals, the actual column being the profit and loss of the months', function () {
    $this->post(budgetUrl('budgets'), ['fiscal_year_id' => budgetYear('2026-01-01'), 'name' => 'Main'])->assertRedirect();
    $budget = budgetNamed('Main');
    $this->put(budgetUrl("budgets/{$budget->id}"), ['name' => 'Main', 'amounts' => [
        budgetAccount('4000')->id => [1 => '400', 2 => '400', 3 => '400'],
        budgetAccount('6000')->id => [1 => '200', 2 => '200', 3 => '200'],
    ]])->assertSessionHasNoErrors();

    $admin = costCentre('ADM')->id;
    budgetEntry('2026-01-10', '1000', '4000', '700');
    budgetEntry('2026-02-05', '1000', '4000', '300');
    budgetEntry('2026-02-01', '6000', '1000', '260.50', $admin);
    budgetEntry('2026-02-15', '6900', '1000', '15');
    budgetEntry('2026-03-01', '1000', '4000', '999'); // outside January–February

    $this->get(budgetUrl("reports/budget-vs-actual?budget={$budget->id}&from=1&to=2"))->assertInertia(fn (Assert $page) => $page
        ->component('reports/budget-vs-actual')
        ->where('fromDate', '2026-01-01')
        ->where('toDate', '2026-02-28')
        ->where('revenue.0.budgetMinor', 80000)
        ->where('revenue.0.actualMinor', 100000)
        ->where('revenue.0.favourable', true)
        ->where('expenses.0.code', '6000')
        ->where('expenses.0.varianceMinor', -13950)
        ->where('expenses.0.favourable', true)
        ->where('expenses.1.code', '6900')
        ->where('expenses.1.budgetMinor', 0)
        ->where('expenses.1.favourable', false)
        ->where('profit.budgetMinor', 40000)
        ->where('profit.actualMinor', 100000 - 26050 - 1500));

    // The actual column is the P&L of the months.
    $report = $this->asTenant('acme', fn () => app(Statements::class)->profitAndLoss(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-02-28')));
    $this->get(budgetUrl("reports/budget-vs-actual?budget={$budget->id}&from=1&to=2"))->assertInertia(fn (Assert $page) => $page
        ->where('revenue_total.actualMinor', $report['revenueMinor'])
        ->where('expenses_total.actualMinor', $report['expensesMinor'])
        ->where('profit.actualMinor', $report['profitMinor']));

    // A cost centre's budget is compared with its lines only.
    $this->post(budgetUrl('budgets'), ['fiscal_year_id' => budgetYear('2026-01-01'), 'name' => 'Admin', 'cost_centre_id' => $admin])->assertRedirect();
    $this->get(budgetUrl('reports/budget-vs-actual?budget='.budgetNamed('Admin')->id.'&from=1&to=2'))->assertInertia(fn (Assert $page) => $page
        ->where('budget.costCentre', 'ADM · ADM')
        ->has('revenue', 0)
        ->has('expenses', 1)
        ->where('expenses_total.actualMinor', 26050));

    $this->get(budgetUrl("reports/budget-vs-actual?budget={$budget->id}&from=3&to=2"))->assertSessionHasErrors('from');
    $this->get(budgetUrl("reports/budget-vs-actual?budget={$budget->id}&from=1&to=2&export=report"))
        ->assertOk()
        ->assertDownload();
});

it('keeps budgets to those who manage them, and deletes them', function () {
    $this->post(budgetUrl('budgets'), ['fiscal_year_id' => budgetYear('2026-01-01'), 'name' => 'Main'])->assertRedirect();
    $budget = budgetNamed('Main');

    $this->actingAsMember('acme', ['finance.reports.view']);
    $this->get(budgetUrl('budgets'))->assertInertia(fn (Assert $page) => $page->component('budgets/index')->has('budgets', 1));
    $this->get(budgetUrl("budgets/{$budget->id}"))->assertOk();
    $this->get(budgetUrl('reports/budget-vs-actual'))->assertInertia(fn (Assert $page) => $page->where('budget.id', $budget->id));
    $this->put(budgetUrl("budgets/{$budget->id}"), ['name' => 'X'])->assertForbidden();
    $this->post(budgetUrl('budgets'), ['fiscal_year_id' => budgetYear('2026-01-01'), 'name' => 'Other'])->assertForbidden();
    $this->delete(budgetUrl("budgets/{$budget->id}"))->assertForbidden();

    $this->actingAsMember('acme', PLANNER);
    $this->put(budgetUrl("budgets/{$budget->id}"), ['name' => 'Main', 'amounts' => [budgetAccount('4000')->id => [1 => '5']]])->assertSessionHasNoErrors();
    $this->delete(budgetUrl("budgets/{$budget->id}"))->assertRedirect();
    expect($this->asTenant('acme', fn () => [Budget::query()->count(), BudgetLine::query()->count()]))->toBe([0, 0]);
});
