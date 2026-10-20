<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Finance\Budgets;
use App\Models\Account;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\DimensionValue;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Support\CsvExport;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Budgets per financial year, and budget against actual.
 */
class BudgetController extends Controller
{
    public function index(): Response
    {
        $totals = BudgetLine::query()
            ->join('accounts', 'accounts.id', '=', 'budget_lines.account_id')
            ->groupBy('budget_lines.budget_id', 'accounts.type')
            ->selectRaw('budget_lines.budget_id, accounts.type, SUM(budget_lines.amount_minor) AS total')
            ->toBase()
            ->get();
        $total = fn (string $budget, AccountType $type) => (int) ($totals->first(fn ($row) => $row->budget_id === $budget && $row->type === $type->value)->total ?? 0);

        return Inertia::render('budgets/index', [
            'budgets' => Budget::query()->with(['year', 'costCentre'])
                ->join('fiscal_years', 'fiscal_years.id', '=', 'budgets.fiscal_year_id')
                ->orderByDesc('fiscal_years.starts_on')->orderBy('budgets.name')
                ->select('budgets.*')
                ->get()
                ->map(fn (Budget $budget) => [
                    'id' => $budget->id,
                    'name' => $budget->name,
                    'year' => $budget->year->name,
                    'costCentre' => $budget->costCentre?->label(),
                    'revenueMinor' => $total($budget->id, AccountType::Revenue),
                    'expensesMinor' => $total($budget->id, AccountType::Expense),
                    'updatedAt' => $budget->updated_at->toIso8601String(),
                ]),
            'years' => FiscalYear::query()->orderByDesc('starts_on')->get()->map(fn (FiscalYear $year) => ['id' => $year->id, 'label' => $year->name]),
            'costCentres' => $this->dimensionOptions()['costCentres'],
        ]);
    }

    public function store(Request $request, Budgets $budgets): RedirectResponse
    {
        $data = $request->validate([
            'fiscal_year_id' => ['required', 'uuid', $this->ours('fiscal_years')],
            'name' => ['required', 'string', 'max:100'],
            'cost_centre_id' => ['nullable', 'uuid', $this->ours('dimension_values')->where('kind', DimensionValue::COST_CENTRE)],
            'copy' => ['nullable', Rule::in(['none', 'actuals'])],
            'uplift' => ['nullable', 'string', 'regex:/^-?\d{1,3}(\.\d{1,2})?$/'],
        ]);

        $budget = $budgets->create(
            FiscalYear::query()->findOrFail((string) $data['fiscal_year_id']),
            trim((string) $data['name']),
            isset($data['cost_centre_id']) ? DimensionValue::query()->findOrFail((string) $data['cost_centre_id']) : null,
            ($data['copy'] ?? 'none') === 'actuals',
            ($data['uplift'] ?? '') === '' ? '0' : (string) $data['uplift'],
            $this->user($request),
        );

        return redirect()->route('budgets.show', $budget)->with('success', "Budget {$budget->name} added.");
    }

    /**
     * The budget as a grid: revenue and expense accounts by month.
     */
    public function show(Budget $budget): Response
    {
        $budget->load(['year.periods', 'costCentre']);
        $amounts = [];
        foreach ($budget->lines as $line) {
            $amounts[$line->account_id][$line->period] = $line->amount_minor;
        }

        return Inertia::render('budgets/show', [
            'budget' => [
                'id' => $budget->id,
                'name' => $budget->name,
                'year' => $budget->year->name,
                'costCentre' => $budget->costCentre?->label(),
            ],
            'periods' => $budget->year->periods->map(fn (FiscalPeriod $period) => ['number' => $period->number, 'label' => $period->label()]),
            'accounts' => $this->budgetAccounts(array_keys($amounts))->map(fn (Account $account) => [
                'id' => $account->id,
                'label' => $account->label(),
                'type' => $account->type->value,
            ]),
            'amounts' => (object) $amounts,
        ]);
    }

    public function update(Request $request, Budget $budget, Budgets $budgets): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('budgets', 'name')->where('organization_id', $this->organizationId())->where('fiscal_year_id', $budget->fiscal_year_id)->ignore($budget->id)],
            'amounts' => ['nullable', 'array', 'max:500'],
            'amounts.*' => ['array', 'max:24'],
            'amounts.*.*' => ['nullable', 'string', 'regex:/^-?\d{1,12}(\.\d{1,2})?$/'],
        ]);

        $allowed = $this->budgetAccounts(array_values(array_map('strval', $budget->lines()->distinct()->pluck('account_id')->all())))->keyBy('id');
        $amounts = [];
        foreach ($data['amounts'] ?? [] as $accountId => $months) {
            if (! Str::isUuid((string) $accountId) || ! $allowed->has((string) $accountId)) {
                throw ValidationException::withMessages(['amounts' => 'Budget revenue and expense accounts only.']);
            }
            foreach ($months as $period => $amount) {
                if (is_string($amount) && $amount !== '') {
                    $negative = str_starts_with($amount, '-');
                    $minor = $this->amountIn(ltrim($amount, '-'), null, "amounts.{$accountId}.{$period}");
                    $amounts[(string) $accountId][(int) $period] = $negative ? -$minor : $minor;
                }
            }
        }

        $budget->forceFill(['name' => trim((string) $data['name'])])->save();
        $budgets->save($budget, $amounts);

        return back()->with('success', "Budget {$budget->name} saved.");
    }

    public function destroy(Budget $budget): RedirectResponse
    {
        $budget->delete();

        return redirect()->route('budgets.index')->with('success', "Budget {$budget->name} deleted.");
    }

    /**
     * Budget against actual for a range of months of the budget's year.
     */
    public function report(Request $request, Budgets $budgets): Response|StreamedResponse
    {
        $data = $request->validate([
            'budget' => ['nullable', 'uuid'],
            'from' => ['nullable', 'integer', 'min:1', 'max:24'],
            'to' => ['nullable', 'integer', 'min:1', 'max:24'],
            'export' => ['nullable', Rule::in(['report'])],
        ]);

        $all = Budget::query()->with(['year', 'costCentre'])
            ->join('fiscal_years', 'fiscal_years.id', '=', 'budgets.fiscal_year_id')
            ->orderByDesc('fiscal_years.starts_on')->orderBy('budgets.name')
            ->select('budgets.*')->get();
        $budget = isset($data['budget'])
            ? $all->firstWhere('id', $data['budget']) ?? abort(404)
            // The current year's first budget, else the latest.
            : $all->first(fn (Budget $budget) => CarbonImmutable::today()->between($budget->year->starts_on, $budget->year->ends_on)) ?? $all->first();

        $options = $all->map(fn (Budget $budget) => [
            'id' => $budget->id,
            'label' => "{$budget->year->name} · {$budget->name}".($budget->costCentre === null ? '' : " ({$budget->costCentre->label()})"),
        ]);

        if ($budget === null) {
            return Inertia::render('reports/budget-vs-actual', ['budgets' => $options, 'budget' => null]);
        }

        $periods = $budget->year->periods;
        // Up to this month when the year is running, else the whole year.
        $current = $periods->first(fn (FiscalPeriod $period) => CarbonImmutable::today()->between($period->starts_on, $period->ends_on));
        $defaultTo = $current instanceof FiscalPeriod ? $current->number : $periods->count();
        $to = $periods->firstWhere('number', (int) ($data['to'] ?? $defaultTo)) ?? $periods->last();
        $from = $periods->firstWhere('number', (int) ($data['from'] ?? 1)) ?? $periods->first();
        if ($from === null || $to === null) {
            abort(404);
        }
        if ($from->number > $to->number) {
            throw ValidationException::withMessages(['from' => 'The first month comes before the last.']);
        }

        $report = $budgets->report($budget, $from, $to);

        if (($data['export'] ?? null) === 'report') {
            $rows = fn (string $section, array $rows) => array_map(fn (array $row) => ['section' => $section] + $row, $rows);

            return CsvExport::download("budget-vs-actual-{$budget->year->name}-{$budget->name}", [
                'Section' => 'section', 'Code' => 'code', 'Account' => 'name',
                'Budget' => 'budgetMinor', 'Actual' => 'actualMinor', 'Variance' => 'varianceMinor',
            ], [...$rows('Revenue', $report['revenue']), ...$rows('Expenses', $report['expenses'])]);
        }

        return Inertia::render('reports/budget-vs-actual', [
            'budgets' => $options,
            'budget' => [
                'id' => $budget->id,
                'name' => $budget->name,
                'year' => $budget->year->name,
                'costCentre' => $budget->costCentre?->label(),
                'costCentreId' => $budget->cost_centre_id,
            ],
            'periods' => $periods->map(fn (FiscalPeriod $period) => ['number' => $period->number, 'label' => $period->label()]),
            'from' => $from->number,
            'to' => $to->number,
            'fromDate' => $from->starts_on->toDateString(),
            'toDate' => $to->ends_on->toDateString(),
        ] + $report);
    }

    /**
     * Open revenue and expense accounts, and closed ones the budget uses.
     *
     * @param  list<string>  $used
     * @return Collection<int, Account>
     */
    private function budgetAccounts(array $used): Collection
    {
        return Account::query()
            ->whereIn('type', [AccountType::Revenue->value, AccountType::Expense->value])
            ->where(fn ($query) => $query->where('active', true)->orWhereIn('id', $used))
            ->orderBy('code')
            ->get();
    }
}
