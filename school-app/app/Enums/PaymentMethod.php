<?php

namespace App\Enums;

/**
 * How an invoice is paid: the methods Finance knows (finance.payment.received).
 */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case MobileMoney = 'mobile_money';
    case Card = 'card';
    case BankTransfer = 'bank_transfer';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::MobileMoney => 'Mobile money',
            self::Card => 'Card',
            self::BankTransfer => 'Bank transfer',
            self::Other => 'Other',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $method) => ['value' => $method->value, 'label' => $method->label()], self::cases());
    }
}
