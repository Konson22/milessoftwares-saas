<?php

namespace App\Finance;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\DimensionValue;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Budgets: a planned amount per revenue or expense account and month of a
 * financial year, compared with the profit and loss. A budget for a cost
 * centre is compared with that cost centre's lines only.
 */
class Budgets
{
    public function __construct(private readonly Statements $statements) {}

    /**
     * A new budget, empty or filled from the previous year's actuals month by
     * month, raised or lowered by a percentage.
     *
     * @throws ValidationException when the name is taken, or there is no year to copy
     */
    public function create(FiscalYear $year, string $name, ?DimensionValue $costCentre, bool $copyActuals, string $upliftPercent, User $by): Budget
    {
        return DB::transaction(function () use ($year, $name, $costCentre, $copyActuals, $upliftPercent, $by) {
            if (Budget::query()->where('fiscal_year_id', $year->id)->where('name', $name)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['name' => "{$year->name} already has a budget called {$name}."]);
            }

            $budget = Budget::query()->create([
                'fiscal_year_id' => $year->id,
                'name' => $name,
                'cost_centre_id' => $costCentre?->id,
                'created_by' => $by->id,
            ]);

            if ($copyActuals) {
                $this->save($budget, $this->actualsOfYearBefore($year, $costCentre, $upliftPercent));
            }

            return $budget;
        });
    }

    /**
     * Replaces the budget's amounts. Zero amounts are not kept.
     *
     * @param  array<string, array<int, int>>  $amounts  account id => period number => amount
     */
    public function save(Budget $budget, array $amounts): void
    {
        $periods = $budget->year->periods()->count();

        DB::transaction(function () use ($budget, $amounts, $periods) {
            $budget->lines()->delete();

            foreach ($amounts as $accountId => $months) {
                foreach ($months as $period => $amount) {
                    if ($amount !== 0 && $period >= 1 && $period <= $periods) {
                        $budget->lines()->create(['account_id' => $accountId, 'period' => $period, 'amount_minor' => $amount]);
                    }
                }
            }

            $budget->touch();
        });
    }

    /**
     * Budget against actual for months $from to $to of the budget's year.
     * The actual column is the profit and loss of those months (for the
     * budget's cost centre, if it has one).
     *
     * @return array{
     *     revenue: list<array{id: string, code: string, name: string, budgetMinor: int, actualMinor: int, varianceMinor: int, favourable: bool}>,
     *     expenses: list<array{id: string, code: string, name: string, budgetMinor: int, actualMinor: int, varianceMinor: int, favourable: bool}>,
     *     revenue_total: array{budgetMinor: int, actualMinor: int, varianceMinor: int, favourable: bool},
     *     expenses_total: array{budgetMinor: int, actualMinor: int, varianceMinor: int, favourable: bool},
     *     profit: array{budgetMinor: int, actualMinor: int, varianceMinor: int, favourable: bool},
     * }
     */
    public function report(Budget $budget, FiscalPeriod $from, FiscalPeriod $to): array
    {
        $actual = $this->statements->profitAndLoss($from->starts_on, $to->ends_on, $this->dimensions($budget));
        $actualByAccount = array_column([...$actual['revenue'], ...$actual['expenses']], 'amountMinor', 'id');

        $budgeted = [];
        foreach (BudgetLine::query()->where('budget_id', $budget->id)->whereBetween('period', [$from->number, $to->number])
            ->groupBy('account_id')->selectRaw('account_id, SUM(amount_minor) AS total')->toBase()->get() as $row) {
            $budgeted[(string) $row->account_id] = (int) $row->total;
        }

        $sections = [];
        $totals = [];
        foreach (['revenue' => AccountType::Revenue, 'expenses' => AccountType::Expense] as $key => $type) {
            $rows = [];
            foreach (Account::query()->where('type', $type)->orderBy('code')->get() as $account) {
                $planned = $budgeted[$account->id] ?? 0;
                $spent = $actualByAccount[$account->id] ?? 0;
                if ($planned !== 0 || $spent !== 0) {
                    $rows[] = ['id' => $account->id, 'code' => $account->code, 'name' => $account->name] + self::compare($planned, $spent, $type === AccountType::Revenue);
                }
            }
            $sections[$key] = $rows;
            $totals[$key] = self::compare(array_sum(array_column($rows, 'budgetMinor')), array_sum(array_column($rows, 'actualMinor')), $type === AccountType::Revenue);
        }

        return [
            'revenue' => $sections['revenue'],
            'expenses' => $sections['expenses'],
            'revenue_total' => $totals['revenue'],
            'expenses_total' => $totals['expenses'],
            'profit' => self::compare(
                $totals['revenue']['budgetMinor'] - $totals['expenses']['budgetMinor'],
                $totals['revenue']['actualMinor'] - $totals['expenses']['actualMinor'],
                true,
            ),
        ];
    }

    /**
     * The previous year's profit and loss, month by month (matched by month
     * number), changed by a percentage.
     *
     * @return array<string, array<int, int>>
     */
    private function actualsOfYearBefore(FiscalYear $year, ?DimensionValue $costCentre, string $upliftPercent): array
    {
        $previous = FiscalYear::query()->whereDate('ends_on', '<', $year->starts_on)->orderByDesc('starts_on')->first();
        if ($previous === null) {
            throw ValidationException::withMessages(['copy' => "There is no year before {$year->name} to copy."]);
        }
        if (! is_numeric($upliftPercent)) {
            throw ValidationException::withMessages(['uplift' => 'The change is a percentage, such as 5 or -2.5.']);
        }
        $factor = bcdiv(bcadd('100', $upliftPercent, 6), '100', 8);
        $dimensions = $costCentre === null ? [] : [DimensionValue::COST_CENTRE => $costCentre->id];

        $amounts = [];
        foreach ($previous->periods as $period) {
            $report = $this->statements->profitAndLoss($period->starts_on, $period->ends_on, $dimensions);
            foreach ([...$report['revenue'], ...$report['expenses']] as $row) {
                $amounts[$row['id']][$period->number] = self::scale($row['amountMinor'], $factor);
            }
        }

        return $amounts;
    }

    /**
     * @param  numeric-string  $factor
     */
    private static function scale(int $minor, string $factor): int
    {
        $value = bcmul((string) $minor, $factor, 8);

        // Half away from zero: bcadd with scale 0 truncates toward zero.
        return (int) bcadd($value, str_starts_with($value, '-') ? '-0.5' : '0.5', 0);
    }

    /**
     * @return array<string, string>
     */
    private function dimensions(Budget $budget): array
    {
        return $budget->cost_centre_id === null ? [] : [DimensionValue::COST_CENTRE => $budget->cost_centre_id];
    }

    /**
     * Over budget is good for income and bad for spending.
     *
     * @return array{budgetMinor: int, actualMinor: int, varianceMinor: int, favourable: bool}
     */
    private static function compare(int $budget, int $actual, bool $income): array
    {
        $variance = $actual - $budget;

        return ['budgetMinor' => $budget, 'actualMinor' => $actual, 'varianceMinor' => $variance, 'favourable' => $income ? $variance >= 0 : $variance <= 0];
    }
}
