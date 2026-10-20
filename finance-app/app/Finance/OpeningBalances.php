<?php

namespace App\Finance;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Journal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What the balance sheet accounts held when the books moved to Miles: one
 * journal on the first day of the first financial year. Whatever does not
 * balance goes to opening balance equity. Entering them again reverses the
 * previous journal and posts the new figures, while that month is open.
 */
class OpeningBalances
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly Periods $periods,
        private readonly ChartOfAccounts $chart,
    ) {}

    /**
     * The journal holding the opening balances now, if any.
     */
    public function journal(): ?Journal
    {
        return Journal::query()
            ->where('source_type', Journal::OPENING_BALANCES)
            ->whereNull('reversed_by_id')
            ->whereNull('reversal_of_id')
            ->latest('created_at')
            ->first();
    }

    /**
     * Each account's opening balance, debit positive and credit negative,
     * without the opening balance equity line.
     *
     * @return array<string, int> keyed by account id
     */
    public function current(): array
    {
        $journal = $this->journal();
        if ($journal === null) {
            return [];
        }

        $equity = Account::query()->where('system_key', Account::OPENING_BALANCE)->value('id');
        $balances = [];
        foreach ($journal->lines as $line) {
            if ($line->account_id !== $equity) {
                $balances[$line->account_id] = ($balances[$line->account_id] ?? 0) + $line->debit_minor - $line->credit_minor;
            }
        }

        return $balances;
    }

    /**
     * @param  array<string, int>  $balances  keyed by account id, debit positive and credit negative
     *
     * @throws ValidationException before any year is set up, for a revenue or expense account, or when the first month is locked
     */
    public function save(array $balances, User $by): ?Journal
    {
        $this->chart->ensure();
        $start = $this->periods->booksStart();

        if ($start === null) {
            throw ValidationException::withMessages(['balances' => 'Set up the first financial year first: opening balances are dated its first day.']);
        }

        $equity = Account::query()->where('system_key', Account::OPENING_BALANCE)->first();
        if ($equity === null) {
            throw ValidationException::withMessages(['balances' => 'The opening balance equity account (3900) is missing: code 3900 is used by another account.']);
        }

        $accounts = Account::query()->whereIn('id', array_keys($balances))->get()->keyBy('id');
        $lines = [];
        $net = 0;

        foreach ($balances as $id => $amount) {
            $account = $accounts->get($id);

            if ($account === null || $account->id === $equity->id) {
                throw ValidationException::withMessages(["balances.{$id}" => 'Choose another account.']);
            }
            if (in_array($account->type, [AccountType::Revenue, AccountType::Expense], true)) {
                throw ValidationException::withMessages(["balances.{$id}" => "{$account->label()} is a {$account->type->value} account; opening balances are for the balance sheet."]);
            }
            if ($amount === 0) {
                continue;
            }

            $lines[] = $amount > 0
                ? ['account' => $account, 'debit' => $amount]
                : ['account' => $account, 'credit' => -$amount];
            $net += $amount;
        }

        if ($net !== 0) {
            $lines[] = $net > 0
                ? ['account' => $equity, 'credit' => $net]
                : ['account' => $equity, 'debit' => -$net];
        }

        return DB::transaction(function () use ($lines, $start, $by) {
            $previous = $this->journal();

            if ($previous !== null) {
                $this->ledger->reverse($previous, $start, "Opening balances replaced ({$previous->number})", $by);
            }

            return $lines === [] ? null : $this->ledger->post($start, 'Opening balances', $lines, ['source_type' => Journal::OPENING_BALANCES], $by);
        });
    }
}
