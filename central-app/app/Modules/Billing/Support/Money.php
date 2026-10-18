<?php

namespace App\Modules\Billing\Support;

/**
 * Amounts are kept in minor units (cents). Forms send major units with up
 * to two decimals; this converts without going through floats.
 */
final class Money
{
    /**
     * Validation rule for an amount typed in major units.
     */
    public const RULE = 'regex:/^\d{1,10}(\.\d{1,2})?$/';

    public static function toMinor(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', trim($amount), 2), 2, '');

        return (int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    public static function format(int $minor, string $currency): string
    {
        return $currency.' '.number_format($minor / 100, 2);
    }
}
