<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Quantities with two decimals. Exact, so 0.1 + 0.2 is 0.30 and a delivery is
 * never refused or let through by a rounding error.
 */
final class Quantity
{
    public static function add(string $a, string $b): string
    {
        return bcadd(self::number($a), self::number($b), 2);
    }

    public static function subtract(string $a, string $b): string
    {
        return bcsub(self::number($a), self::number($b), 2);
    }

    public static function compare(string $a, string $b): int
    {
        return bccomp(self::number($a), self::number($b), 2);
    }

    /**
     * Whether a/b equals c/d, compared crosswise: exact, with no division.
     */
    public static function sameRatio(string $a, string $b, string $c, string $d): bool
    {
        return bccomp(bcmul(self::number($a), self::number($d), 4), bcmul(self::number($c), self::number($b), 4), 4) === 0;
    }

    /**
     * part/whole of an amount in minor units, half up.
     */
    public static function shareOf(int $amountMinor, string $part, string $whole): int
    {
        $value = bcdiv(bcmul((string) $amountMinor, self::number($part), 4), self::number($whole), 4);

        return (int) bcadd($value, '0.5', 0);
    }

    /**
     * What this many cost at a price in minor units, half up.
     */
    public static function price(string $quantity, int $unitMinor): int
    {
        return (int) bcadd(bcmul(self::number($quantity), (string) $unitMinor, 2), '0.5', 0);
    }

    /**
     * @return numeric-string
     */
    private static function number(string $value): string
    {
        return is_numeric($value) ? $value : throw new InvalidArgumentException("Not a quantity: {$value}");
    }
}
