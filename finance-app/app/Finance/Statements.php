<?php

namespace App\Finance;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\DimensionValue;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Trial balance, profit and loss, and balance sheet, from the ledger.
 */
class Statements
{
    public function __construct(private readonly Balances $balances) {}

    /**
     * Every account with a balance on the day, on its debit or credit side.
     * Limited to a cost centre or project, only the lines that name it count,
     * so it need not balance.
     *
     * @param  array<string, string>  $dimensions  see Balances::totals
     * @return array{rows: list<array{id: string, code: string, name: string, type: string, debitMinor: int, creditMinor: int}>, debitMinor: int, creditMinor: int}
     */
    public function trialBalance(CarbonImmutable $asOf, array $dimensions = []): array
    {
        $totals = $this->balances->totals(to: $asOf, dimensions: $dimensions);
        $rows = [];

        foreach ($this->accounts() as $account) {
            $net = ($totals[$account->id]['debits'] ?? 0) - ($totals[$account->id]['credits'] ?? 0);
            if ($net === 0) {
                continue;
            }

            $rows[] = [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type->value,
                'debitMinor' => max($net, 0),
                'creditMinor' => max(-$net, 0),
            ];
        }

        return [
            'rows' => $rows,
            'debitMinor' => array_sum(array_column($rows, 'debitMinor')),
            'creditMinor' => array_sum(array_column($rows, 'creditMinor')),
        ];
    }

    /**
     * The trading result, without year-end closing journals (which would
     * bring a closed year's result to zero).
     *
     * @param  array<string, string>  $dimensions  see Balances::totals
     * @return array{revenue: list<array{id: string, code: string, name: string, amountMinor: int}>, expenses: list<array{id: string, code: string, name: string, amountMinor: int}>, revenueMinor: int, expensesMinor: int, profitMinor: int}
     */
    public function profitAndLoss(CarbonImmutable $from, CarbonImmutable $to, array $dimensions = []): array
    {
        $totals = $this->balances->totals($from, $to, withClosing: false, dimensions: $dimensions);
        $revenue = $this->section(AccountType::Revenue, $totals);
        $expenses = $this->section(AccountType::Expense, $totals);
        $revenueMinor = array_sum(array_column($revenue, 'amountMinor'));
        $expensesMinor = array_sum(array_column($expenses, 'amountMinor'));

        return [
            'revenue' => $revenue,
            'expenses' => $expenses,
            'revenueMinor' => $revenueMinor,
            'expensesMinor' => $expensesMinor,
            'profitMinor' => $revenueMinor - $expensesMinor,
        ];
    }

    /**
     * Profit and loss with a column per cost centre or project (those with
     * income or spending in the period), one for lines without any, and the
     * total, which is the plain profit and loss.
     *
     * @return array{
     *     columns: list<array{id: string, label: string}>,
     *     revenue: list<array{id: string, code: string, name: string, amounts: array<string, int>, amountMinor: int}>,
     *     expenses: list<array{id: string, code: string, name: string, amounts: array<string, int>, amountMinor: int}>,
     *     revenueTotals: array<string, int>, expenseTotals: array<string, int>, profit: array<string, int>,
     *     revenueMinor: int, expensesMinor: int, profitMinor: int,
     * }
     */
    public function profitAndLossBy(string $kind, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $totals = $this->balances->totalsBy($kind, $from, $to, withClosing: false);
        $revenue = $this->splitSection(AccountType::Revenue, $totals);
        $expenses = $this->splitSection(AccountType::Expense, $totals);

        $used = [];
        foreach ([...$revenue, ...$expenses] as $row) {
            $used += array_fill_keys(array_keys($row['amounts']), true);
        }
        $columns = array_values(DimensionValue::query()->ofKind($kind)->whereIn('id', array_diff(array_keys($used), [Balances::NONE]))->orderBy('code')->get()
            ->map(fn (DimensionValue $value) => ['id' => $value->id, 'label' => $value->label()])
            ->all());
        if (isset($used[Balances::NONE])) {
            $columns[] = ['id' => Balances::NONE, 'label' => 'No '.strtolower(DimensionValue::kindLabel($kind))];
        }

        $sum = function (array $rows) use ($columns): array {
            $totals = array_fill_keys(array_column($columns, 'id'), 0);
            foreach ($rows as $row) {
                foreach ($row['amounts'] as $value => $amount) {
                    $totals[$value] += $amount;
                }
            }

            return $totals;
        };
        $revenueTotals = $sum($revenue);
        $expenseTotals = $sum($expenses);
        $profit = [];
        foreach ($columns as $column) {
            $profit[$column['id']] = $revenueTotals[$column['id']] - $expenseTotals[$column['id']];
        }
        $revenueMinor = array_sum(array_column($revenue, 'amountMinor'));
        $expensesMinor = array_sum(array_column($expenses, 'amountMinor'));

        return [
            'columns' => $columns,
            'revenue' => $revenue,
            'expenses' => $expenses,
            'revenueTotals' => $revenueTotals,
            'expenseTotals' => $expenseTotals,
            'profit' => $profit,
            'revenueMinor' => $revenueMinor,
            'expensesMinor' => $expensesMinor,
            'profitMinor' => $revenueMinor - $expensesMinor,
        ];
    }

    /**
     * What is owned and owed on the day. Profit not yet closed into retained
     * earnings shows as current earnings, so the sheet always balances.
     *
     * @return array{assets: list<array{id: string, code: string, name: string, amountMinor: int}>, liabilities: list<array{id: string, code: string, name: string, amountMinor: int}>, equity: list<array{id: string, code: string, name: string, amountMinor: int}>, earningsMinor: int, assetsMinor: int, liabilitiesMinor: int, equityMinor: int}
     */
    public function balanceSheet(CarbonImmutable $asOf): array
    {
        $totals = $this->balances->totals(to: $asOf);
        $assets = $this->section(AccountType::Asset, $totals);
        $liabilities = $this->section(AccountType::Liability, $totals);
        $equity = $this->section(AccountType::Equity, $totals);
        $earnings = array_sum(array_column($this->section(AccountType::Revenue, $totals), 'amountMinor'))
            - array_sum(array_column($this->section(AccountType::Expense, $totals), 'amountMinor'));

        return [
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'earningsMinor' => $earnings,
            'assetsMinor' => array_sum(array_column($assets, 'amountMinor')),
            'liabilitiesMinor' => array_sum(array_column($liabilities, 'amountMinor')),
            'equityMinor' => array_sum(array_column($equity, 'amountMinor')) + $earnings,
        ];
    }

    /**
     * A statement next to the same statement for another period: each
     * section's rows (every account in either) get compareMinor, and the
     * other period's totals are under "compare".
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $previous
     * @param  list<string>  $sections
     * @return array<string, mixed>
     */
    public static function compared(array $current, array $previous, array $sections): array
    {
        foreach ($sections as $section) {
            $rows = [];
            foreach ($current[$section] as $row) {
                $rows[$row['id']] = $row + ['compareMinor' => 0];
            }
            foreach ($previous[$section] as $row) {
                $rows[$row['id']] ??= ['id' => $row['id'], 'code' => $row['code'], 'name' => $row['name'], 'amountMinor' => 0];
                $rows[$row['id']]['compareMinor'] = $row['amountMinor'];
            }
            uasort($rows, fn (array $a, array $b) => strnatcmp($a['code'], $b['code']));
            $current[$section] = array_values($rows);
            unset($previous[$section]);
        }

        return $current + ['compare' => $previous];
    }

    /**
     * @param  array<string, array{debits: int, credits: int}>  $totals
     * @return list<array{id: string, code: string, name: string, amountMinor: int}>
     */
    private function section(AccountType $type, array $totals): array
    {
        $rows = [];

        foreach ($this->accounts()->where('type', $type) as $account) {
            $amount = $type->balance($totals[$account->id]['debits'] ?? 0, $totals[$account->id]['credits'] ?? 0);
            if ($amount !== 0) {
                $rows[] = ['id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'amountMinor' => $amount];
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, array<string, array{debits: int, credits: int}>>  $totals  account id => value id => totals
     * @return list<array{id: string, code: string, name: string, amounts: array<string, int>, amountMinor: int}>
     */
    private function splitSection(AccountType $type, array $totals): array
    {
        $rows = [];

        foreach ($this->accounts()->where('type', $type) as $account) {
            $amounts = [];
            foreach ($totals[$account->id] ?? [] as $value => $total) {
                $amount = $type->balance($total['debits'], $total['credits']);
                if ($amount !== 0) {
                    $amounts[$value] = $amount;
                }
            }
            if ($amounts !== []) {
                $rows[] = ['id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'amounts' => $amounts, 'amountMinor' => array_sum($amounts)];
            }
        }

        return $rows;
    }

    /**
     * @return Collection<int, Account>
     */
    private function accounts(): Collection
    {
        return Account::query()->orderBy('code')->get();
    }
}
