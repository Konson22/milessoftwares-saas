<?php

namespace App\Finance;

use App\Enums\AccountType;
use App\Enums\CashFlowActivity;
use App\Models\Account;
use Carbon\CarbonImmutable;

/**
 * Financial ratios for a period: liquidity and leverage from the balance
 * sheet on its last day, profitability and efficiency from its profit and
 * loss. Both come from the same ledger as the statements.
 *
 * Current or not follows the account's place in the cash flow statement
 * (which the accountant can change on the chart of accounts): banks, cash
 * and operating assets and liabilities (receivable, inventory, payable,
 * tax) are current; investing assets and financing liabilities are not.
 * Cost of sales is cost of goods sold and stock losses.
 *
 * A ratio whose denominator is zero (or that makes no sense, such as
 * return on negative equity) is null.
 */
class Ratios
{
    /** Ratio => how it reads, for exports. */
    public const LABELS = [
        'current' => 'Current ratio (×)',
        'quick' => 'Quick ratio (×)',
        'cash' => 'Cash ratio (×)',
        'grossMargin' => 'Gross margin (%)',
        'netMargin' => 'Net margin (%)',
        'returnOnAssets' => 'Return on assets (%)',
        'returnOnEquity' => 'Return on equity (%)',
        'debtToEquity' => 'Debt to equity (×)',
        'debtRatio' => 'Debt ratio (%)',
        'receivableDays' => 'Days sales outstanding',
        'payableDays' => 'Days payables outstanding',
        'inventoryDays' => 'Days inventory on hand',
    ];

    public function __construct(
        private readonly Balances $balances,
        private readonly Statements $statements,
    ) {}

    /**
     * @return array{
     *     from: string, to: string, days: int,
     *     figures: array<string, int>,
     *     ratios: array<string, float|null>,
     * }
     */
    public function for(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $totals = $this->balances->totals(to: $to);
        $pnl = $this->statements->profitAndLoss($from, $to);
        $days = (int) $from->diffInDays($to) + 1;

        $figures = [
            'cashMinor' => 0, 'receivableMinor' => 0, 'inventoryMinor' => 0, 'currentAssetsMinor' => 0, 'assetsMinor' => 0,
            'payableMinor' => 0, 'currentLiabilitiesMinor' => 0, 'liabilitiesMinor' => 0, 'equityMinor' => 0,
            'revenueMinor' => $pnl['revenueMinor'], 'costOfSalesMinor' => 0, 'expensesMinor' => $pnl['expensesMinor'], 'profitMinor' => $pnl['profitMinor'],
        ];

        $earnings = 0;
        foreach (Account::query()->get() as $account) {
            $balance = $account->type->balance($totals[$account->id]['debits'] ?? 0, $totals[$account->id]['credits'] ?? 0);
            $current = $account->isBank() || $account->cashFlowActivity() === CashFlowActivity::Operating;

            match ($account->type) {
                AccountType::Asset => $this->addAsset($figures, $account, $balance, $current),
                AccountType::Liability => $this->addLiability($figures, $account, $balance, $current),
                AccountType::Equity => $figures['equityMinor'] += $balance,
                // Profit not yet closed into retained earnings is equity too.
                AccountType::Revenue => $earnings += $balance,
                AccountType::Expense => $earnings -= $balance,
            };
        }
        $figures['equityMinor'] += $earnings;

        $costOfSales = $this->costOfSalesCodes();
        foreach ($pnl['expenses'] as $row) {
            if (in_array($row['code'], $costOfSales, true)) {
                $figures['costOfSalesMinor'] += $row['amountMinor'];
            }
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'days' => $days,
            'figures' => $figures,
            'ratios' => $this->ratios($figures, $days),
        ];
    }

    /**
     * @param  array<string, int>  $f
     * @return array<string, float|null>
     */
    private function ratios(array $f, int $days): array
    {
        $grossProfit = $f['revenueMinor'] - $f['costOfSalesMinor'];

        return [
            // Liquidity: can the organization pay what falls due soon?
            'current' => self::divide($f['currentAssetsMinor'], $f['currentLiabilitiesMinor']),
            'quick' => self::divide($f['currentAssetsMinor'] - $f['inventoryMinor'], $f['currentLiabilitiesMinor']),
            'cash' => self::divide($f['cashMinor'], $f['currentLiabilitiesMinor']),
            // Profitability, in per cent.
            'grossMargin' => self::percent($grossProfit, $f['revenueMinor']),
            'netMargin' => self::percent($f['profitMinor'], $f['revenueMinor']),
            'returnOnAssets' => self::percent($f['profitMinor'], $f['assetsMinor']),
            'returnOnEquity' => $f['equityMinor'] > 0 ? self::percent($f['profitMinor'], $f['equityMinor']) : null,
            // Leverage.
            'debtToEquity' => $f['equityMinor'] > 0 ? self::divide($f['liabilitiesMinor'], $f['equityMinor']) : null,
            'debtRatio' => self::percent($f['liabilitiesMinor'], $f['assetsMinor']),
            // Efficiency, in days of the period's activity.
            'receivableDays' => self::days($f['receivableMinor'], $f['revenueMinor'], $days),
            'payableDays' => self::days($f['payableMinor'], $f['costOfSalesMinor'] > 0 ? $f['costOfSalesMinor'] : $f['expensesMinor'], $days),
            'inventoryDays' => self::days($f['inventoryMinor'], $f['costOfSalesMinor'], $days),
        ];
    }

    /**
     * @param  array<string, int>  $figures
     */
    private function addAsset(array &$figures, Account $account, int $balance, bool $current): void
    {
        $figures['assetsMinor'] += $balance;
        if ($current) {
            $figures['currentAssetsMinor'] += $balance;
        }
        match (true) {
            $account->isBank() => $figures['cashMinor'] += $balance,
            $account->system_key === Account::RECEIVABLE => $figures['receivableMinor'] += $balance,
            $account->system_key === Account::INVENTORY => $figures['inventoryMinor'] += $balance,
            default => null,
        };
    }

    /**
     * @param  array<string, int>  $figures
     */
    private function addLiability(array &$figures, Account $account, int $balance, bool $current): void
    {
        $figures['liabilitiesMinor'] += $balance;
        if ($current) {
            $figures['currentLiabilitiesMinor'] += $balance;
        }
        if ($account->system_key === Account::PAYABLE) {
            $figures['payableMinor'] += $balance;
        }
    }

    /**
     * @return list<string>
     */
    private function costOfSalesCodes(): array
    {
        return array_values(Account::query()->whereIn('system_key', [Account::PURCHASES, Account::STOCK_LOSSES])->get()->map(fn (Account $account) => $account->code)->all());
    }

    private static function divide(int $numerator, int $denominator): ?float
    {
        return $denominator === 0 ? null : round($numerator / $denominator, 2);
    }

    private static function percent(int $numerator, int $denominator): ?float
    {
        return $denominator === 0 ? null : round($numerator * 100 / $denominator, 1);
    }

    private static function days(int $balance, int $flow, int $days): ?float
    {
        return $flow <= 0 ? null : round($balance * $days / $flow, 1);
    }
}
