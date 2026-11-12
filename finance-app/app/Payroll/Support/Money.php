<?php

namespace App\Payroll\Support;

/**
 * Amounts typed in forms ("1500.50") to integer minor units. Two decimal
 * places, like every currency the platform offers today.
 */
final class Money
{
    /** Validation for an amount typed in a form. */
    public const RULES = ['required', 'numeric', 'min:0', 'max:999999999999', 'decimal:0,2'];

    public static function toMinor(string|int|float|null $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}
