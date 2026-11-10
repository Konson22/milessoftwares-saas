<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Fuel is stored as integer millilitres so litres with three decimals
 * (a pump meter) never become binary fractions. Prices are per litre.
 */
final class Volume
{
    public static function rule(): string
    {
        return 'regex:/^\d{1,7}(\.\d{1,3})?$/';
    }

    public static function toMilli(string $litres): int
    {
        $litres = trim($litres);

        if (preg_match('/^(\d{1,7})(?:\.(\d{1,3}))?$/', $litres, $m) !== 1) {
            throw new InvalidArgumentException("Not a volume: {$litres}");
        }

        return ((int) $m[1]) * 1000 + (int) str_pad($m[2] ?? '', 3, '0');
    }

    public static function toLitres(int $milli): string
    {
        $sign = $milli < 0 ? '-' : '';
        $milli = abs($milli);

        return $sign.intdiv($milli, 1000).'.'.str_pad((string) ($milli % 1000), 3, '0', STR_PAD_LEFT);
    }

    /**
     * Millilitres at a per-litre price, rounded half up, signed like $milli.
     */
    public static function amount(int $milli, int $pricePerLitreMinor): int
    {
        $sign = $milli < 0 ? -1 : 1;
        $milli = abs($milli);

        if ($pricePerLitreMinor < 0) {
            throw new InvalidArgumentException('Price cannot be negative.');
        }

        if ($pricePerLitreMinor > 0 && $milli > intdiv(PHP_INT_MAX - 500, $pricePerLitreMinor)) {
            throw new InvalidArgumentException('Amount is too large.');
        }

        return $sign * (int) intdiv($milli * $pricePerLitreMinor + 500, 1000);
    }

    /**
     * Litres (millilitres) sold for an amount at a per-litre price.
     */
    public static function milliForAmount(int $amountMinor, int $pricePerLitreMinor): int
    {
        if ($pricePerLitreMinor <= 0 || $amountMinor < 0) {
            throw new InvalidArgumentException('Price must be positive.');
        }

        if ($amountMinor > intdiv(PHP_INT_MAX - $pricePerLitreMinor, 1000)) {
            throw new InvalidArgumentException('Amount is too large.');
        }

        return (int) intdiv($amountMinor * 1000 + intdiv($pricePerLitreMinor, 2), $pricePerLitreMinor);
    }
}
