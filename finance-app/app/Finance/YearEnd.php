<?php

namespace App\Finance;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\Journal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Closing a financial year: every month is locked and one closing journal,
 * dated the last day, brings each revenue and expense account to zero for the
 * year against retained earnings. Reopening reverses that journal; the months
 * stay locked until unlocked one by one.
 */
class YearEnd
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly Balances $balances,
        private readonly ChartOfAccounts $chart,
    ) {}

    /**
     * @throws ValidationException when the year is closed, or an earlier year is open
     */
    public function close(FiscalYear $year, User $by): FiscalYear
    {
        $this->chart->ensure();

        return DB::transaction(function () use ($year, $by) {
            $locked = FiscalYear::query()->whereKey($year->id)->lockForUpdate()->firstOrFail();

            if ($locked->closed()) {
                throw ValidationException::withMessages(['year' => "{$locked->name} is already closed."]);
            }
            $earlier = FiscalYear::query()->whereDate('starts_on', '<', $locked->starts_on)->whereNull('closed_at')->orderBy('starts_on')->first();
            if ($earlier !== null) {
                throw ValidationException::withMessages(['year' => "Close {$earlier->name} first."]);
            }

            $locked->periods()->whereNull('locked_at')->update(['locked_at' => now(), 'locked_by' => $by->id]);

            $lines = $this->closingLines($locked);
            $journal = $lines === [] ? null : $this->ledger->post(
                $locked->ends_on,
                "Year-end close of {$locked->name}",
                $lines,
                ['source_type' => Journal::YEAR_CLOSE, 'source_reference' => $locked->name, 'periods' => Periods::CLOSING],
                $by,
            );

            $locked->forceFill(['closed_at' => now(), 'closed_by' => $by->id, 'closing_journal_id' => $journal?->id])->save();

            return $locked;
        });
    }

    /**
     * @throws ValidationException when the year is open, or a later year is closed
     */
    public function reopen(FiscalYear $year, User $by): FiscalYear
    {
        return DB::transaction(function () use ($year, $by) {
            $locked = FiscalYear::query()->whereKey($year->id)->lockForUpdate()->firstOrFail();

            if (! $locked->closed()) {
                throw ValidationException::withMessages(['year' => "{$locked->name} is not closed."]);
            }
            $later = FiscalYear::query()->whereDate('starts_on', '>', $locked->starts_on)->whereNotNull('closed_at')->orderByDesc('starts_on')->first();
            if ($later !== null) {
                throw ValidationException::withMessages(['year' => "Reopen {$later->name} first."]);
            }

            if ($locked->closing_journal_id !== null) {
                $this->ledger->reverse(
                    Journal::query()->findOrFail($locked->closing_journal_id),
                    $locked->ends_on,
                    "Reopening of {$locked->name}",
                    $by,
                    Journal::YEAR_REOPEN,
                    Periods::CLOSING,
                );
            }

            $locked->forceFill(['closed_at' => null, 'closed_by' => null, 'closing_journal_id' => null])->save();

            return $locked;
        });
    }

    /**
     * Each revenue and expense account's result for the year, turned around,
     * and the difference into retained earnings.
     *
     * @return list<array{account: Account, debit?: int, credit?: int}>
     */
    private function closingLines(FiscalYear $year): array
    {
        $totals = $this->balances->totals($year->starts_on, $year->ends_on, withClosing: false);
        $accounts = Account::query()->whereIn('type', [AccountType::Revenue, AccountType::Expense])->orderBy('code')->get();
        $lines = [];
        $net = 0;

        foreach ($accounts as $account) {
            // Debit balance positive: credit it away (and the other way round).
            $balance = ($totals[$account->id]['debits'] ?? 0) - ($totals[$account->id]['credits'] ?? 0);
            if ($balance === 0) {
                continue;
            }

            $lines[] = $balance > 0
                ? ['account' => $account, 'credit' => $balance]
                : ['account' => $account, 'debit' => -$balance];
            $net += $balance;
        }

        if ($net !== 0) {
            // A profit (credit-heavy accounts, $net < 0) is credited to retained earnings.
            $retained = Account::system(Account::RETAINED_EARNINGS);
            $lines[] = $net < 0
                ? ['account' => $retained, 'credit' => -$net, 'description' => 'Profit for the year']
                : ['account' => $retained, 'debit' => $net, 'description' => 'Loss for the year'];
        }

        return $lines;
    }
}
