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
 *
 * Amounts are in the bank account's currency. For an account held in
 * another currency they are converted at $rate, or the day's rate.
 */
class Banking
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly Currencies $currencies,
        private readonly ChartOfAccounts $chart,
    ) {}

    /**
     * Dr the expense (or other) account / Cr the bank.
     */
    public function moneyOut(Account $bank, Account $to, int $amountMinor, CarbonImmutable $date, string $description, User $by, ?string $rate = null): Journal
    {
        $this->check($bank, $to, $amountMinor);
        $side = $this->side($bank, $amountMinor, $date, $rate);

        return $this->ledger->post($date, $description, [
            ['account' => $to, 'debit' => $side['books'], 'description' => $description],
            ['account' => $bank, 'credit' => $side['books'], 'currency' => $side['currency']],
        ], ['source_type' => 'money_out'], $by);
    }

    /**
     * Dr the bank / Cr the income (or other) account.
     */
    public function moneyIn(Account $bank, Account $from, int $amountMinor, CarbonImmutable $date, string $description, User $by, ?string $rate = null): Journal
    {
        $this->check($bank, $from, $amountMinor);
        $side = $this->side($bank, $amountMinor, $date, $rate);

        return $this->ledger->post($date, $description, [
            ['account' => $bank, 'debit' => $side['books'], 'currency' => $side['currency']],
            ['account' => $from, 'credit' => $side['books'], 'description' => $description],
        ], ['source_type' => 'money_in'], $by);
    }

    /**
     * Dr the receiving bank / Cr the sending bank. $amountMinor leaves in the
     * sender's currency; between accounts in different currencies,
     * $receivedMinor is what arrived in the receiver's, and the difference
     * from the day's rates is an exchange gain or loss.
     */
    public function transfer(Account $from, Account $to, int $amountMinor, CarbonImmutable $date, string $description, User $by, ?int $receivedMinor = null): Journal
    {
        if (! $to->isBank() || $from->id === $to->id) {
            throw ValidationException::withMessages(['to_account_id' => 'Transfer to another bank or cash account.']);
        }
        $this->check($from, $to, $amountMinor);

        $sent = $this->side($from, $amountMinor, $date, null);
        if ($from->currency === $to->currency) {
            $received = ['books' => $sent['books'], 'currency' => $to->currency === null ? null : ['code' => $to->currency, 'amount' => $amountMinor]];
        } elseif ($receivedMinor === null || $receivedMinor <= 0) {
            throw ValidationException::withMessages(['received' => 'Say how much arrived in '.($to->currency ?? Money::currency()).'.']);
        } else {
            $received = $this->side($to, $receivedMinor, $date, null);
        }

        $lines = [
            ['account' => $to, 'debit' => $received['books'], 'currency' => $received['currency']],
            ['account' => $from, 'credit' => $sent['books'], 'currency' => $sent['currency']],
        ];
        $difference = $received['books'] - $sent['books'];
        if ($difference !== 0) {
            $this->chart->ensure();
            $lines[] = ['account' => Account::system(Account::FX), $difference > 0 ? 'credit' : 'debit' => abs($difference), 'description' => 'Exchange difference on the transfer'];
        }

        return $this->ledger->post($date, $description, $lines, ['source_type' => 'transfer'], $by);
    }

    /**
     * An amount in the bank's currency: its books-currency value, and the
     * amount to keep on the line when the bank is held in another currency.
     *
     * @return array{books: int, currency: array{code: string, amount: int}|null}
     */
    private function side(Account $bank, int $amountMinor, CarbonImmutable $date, ?string $rate): array
    {
        if ($bank->currency === null) {
            return ['books' => $amountMinor, 'currency' => null];
        }

        $rate ??= $this->currencies->rate($bank->currency, $date);
        $books = Currencies::toBooks($amountMinor, $bank->currency, $rate);
        if ($books <= 0) {
            throw ValidationException::withMessages(['amount' => 'The amount is too small to record in the books\' currency.']);
        }

        return ['books' => $books, 'currency' => ['code' => $bank->currency, 'amount' => $amountMinor]];
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
