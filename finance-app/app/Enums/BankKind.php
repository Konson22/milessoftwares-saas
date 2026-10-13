<?php

namespace App\Enums;

/**
 * Accounts that hold money: what Banking lists and payments go through.
 */
enum BankKind: string
{
    case Cash = 'cash';
    case Bank = 'bank';
    case MobileMoney = 'mobile_money';
    case Card = 'card';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Bank => 'Bank account',
            self::MobileMoney => 'Mobile money',
            self::Card => 'Card settlements',
        };
    }
}
