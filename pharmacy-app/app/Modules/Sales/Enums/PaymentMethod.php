<?php

namespace App\Modules\Sales\Enums;

/**
 * How a sale is paid: the methods Finance knows (finance.payment.received),
 * or on account (Account), paid later by the customer with one of them.
 */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case MobileMoney = 'mobile_money';
    case Card = 'card';
    case BankTransfer = 'bank_transfer';
    case Other = 'other';
    case Account = 'account';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::MobileMoney => 'Mobile money',
            self::Card => 'Card',
            self::BankTransfer => 'Bank transfer',
            self::Other => 'Other',
            self::Account => 'On account',
        };
    }

    /**
     * Money changes hands now (not on account).
     */
    public function paidNow(): bool
    {
        return $this !== self::Account;
    }

    /**
     * The methods money is taken with.
     *
     * @return list<self>
     */
    public static function payments(): array
    {
        return array_values(array_filter(self::cases(), fn (self $method) => $method->paidNow()));
    }
}
