<?php

namespace App\Finance;

use App\Models\Account;
use App\Models\Journal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Money moving through bank and cash accounts outside invoices and bills:
 * spending, other income, and transfers between accounts.
 */
class Banking
{
    public function __construct(private readonly Ledger $ledger) {}

    /**
     * Dr the expense (or other) account / Cr the bank.
     */
    public function moneyOut(Account $bank, Account $to, int $amountMinor, CarbonImmutable $date, string $description, User $by): Journal
    {
        $this->check($bank, $to, $amountMinor);

        return $this->ledger->post($date, $description, [
            ['account' => $to, 'debit' => $amountMinor, 'description' => $description],
            ['account' => $bank, 'credit' => $amountMinor],
        ], ['source_type' => 'money_out'], $by);
    }

    /**
     * Dr the bank / Cr the income (or other) account.
     */
    public function moneyIn(Account $bank, Account $from, int $amountMinor, CarbonImmutable $date, string $description, User $by): Journal
    {
        $this->check($bank, $from, $amountMinor);

        return $this->ledger->post($date, $description, [
            ['account' => $bank, 'debit' => $amountMinor],
            ['account' => $from, 'credit' => $amountMinor, 'description' => $description],
        ], ['source_type' => 'money_in'], $by);
    }

    /**
     * Dr the receiving bank / Cr the sending bank.
     */
    public function transfer(Account $from, Account $to, int $amountMinor, CarbonImmutable $date, string $description, User $by): Journal
    {
        if (! $to->isBank() || $from->id === $to->id) {
            throw ValidationException::withMessages(['to_account_id' => 'Transfer to another bank or cash account.']);
        }
        $this->check($from, $to, $amountMinor);

        return $this->ledger->post($date, $description, [
            ['account' => $to, 'debit' => $amountMinor],
            ['account' => $from, 'credit' => $amountMinor],
        ], ['source_type' => 'transfer'], $by);
    }

    private function check(Account $bank, Account $other, int $amountMinor): void
    {
        if (! $bank->isBank()) {
            throw ValidationException::withMessages(['account_id' => 'Choose a bank or cash account.']);
        }
        if ($other->id === $bank->id) {
            throw ValidationException::withMessages(['other_account_id' => 'Choose a different account.']);
        }
        if ($amountMinor <= 0) {
            throw ValidationException::withMessages(['amount' => 'The amount must be more than zero.']);
        }
    }
}
