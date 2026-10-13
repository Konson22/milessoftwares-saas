<?php

namespace App\Enums;

/**
 * The five kinds of account. Assets and expenses grow with debits;
 * liabilities, equity and revenue with credits.
 */
enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Revenue = 'revenue';
    case Expense = 'expense';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function increasesWithDebit(): bool
    {
        return $this === self::Asset || $this === self::Expense;
    }

    /**
     * The account's balance in its normal direction, from its debit and
     * credit totals.
     */
    public function balance(int $debits, int $credits): int
    {
        return $this->increasesWithDebit() ? $debits - $credits : $credits - $debits;
    }
}
