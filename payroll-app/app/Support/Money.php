<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Amounts typed in forms ("1500.50") to integer minor units, and other
 * fixed-point helpers. Two decimal places, like every currency the platform
 * offers today. Nothing here goes through a float: "0.29" is 29, never 28.
 */
final class Money
{
    /** Validation for an amount typed in a form. */
    public const RULES = ['required', 'numeric', 'min:0', 'max:999999999999', 'decimal:0,2'];

    public static function toMinor(string|int|float|null $amount): int
    {
        return self::hundredths($amount);
    }

    /**
     * A decimal with up to two places ("7.5", "1500.50", "-3") as an integer
     * count of hundredths: hours as stored (decimal 5,2), or an amount.
     */
    public static function hundredths(string|int|float|null $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if (is_int($value)) {
            return $value * 100;
        }
        if (is_float($value)) {
            // Some database drivers hand decimal columns back as floats.
            $value = number_format($value, 2, '.', '');
        }

        $value = trim($value);
        if (preg_match('/^(-?)(\d*)(?:\.(\d{0,2})0*)?$/', $value, $m) !== 1 || ($m[2] === '' && ($m[3] ?? '') === '')) {
            throw new InvalidArgumentException("Not a decimal with up to two places: {$value}");
        }

        $minor = (int) ($m[2] === '' ? '0' : $m[2]) * 100 + (int) str_pad($m[3] ?? '', 2, '0');

        return $m[1] === '-' ? -$minor : $minor;
    }

    /** Hundredths back to a decimal string for a decimal column: 725 is "7.25". */
    public static function fromHundredths(int $hundredths): string
    {
        $sign = $hundredths < 0 ? '-' : '';
        $hundredths = abs($hundredths);

        return $sign.intdiv($hundredths, 100).'.'.str_pad((string) ($hundredths % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * $amount x $numerator / $denominator, rounded half up (away from zero),
     * in integers: pro-rata pay (days worked / days in the month) and rates.
     */
    public static function share(int $amount, int $numerator, int $denominator): int
    {
        if ($denominator <= 0) {
            throw new InvalidArgumentException('The denominator must be positive.');
        }

        $product = $amount * $numerator;
        $rounded = intdiv(abs($product) * 2 + $denominator, 2 * $denominator);

        return $product < 0 ? -$rounded : $rounded;
    }
}
