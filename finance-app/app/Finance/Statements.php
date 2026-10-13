<?php

namespace App\Finance;

use App\Enums\AccountType;
use App\Models\Account;
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
     *
     * @return array{rows: list<array{id: string, code: string, name: string, type: string, debitMinor: int, creditMinor: int}>, debitMinor: int, creditMinor: int}
     */
    public function trialBalance(CarbonImmutable $asOf): array
    {
        $totals = $this->balances->totals(to: $asOf);
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
     * @return array{revenue: list<array{id: string, code: string, name: string, amountMinor: int}>, expenses: list<array{id: string, code: string, name: string, amountMinor: int}>, revenueMinor: int, expensesMinor: int, profitMinor: int}
     */
    public function profitAndLoss(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $totals = $this->balances->totals($from, $to);
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
     * @return Collection<int, Account>
     */
    private function accounts(): Collection
    {
        return Account::query()->orderBy('code')->get();
    }
}
