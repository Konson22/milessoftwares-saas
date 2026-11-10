<?php

namespace App\Support;

use App\Tenancy\CurrentOrganization;
use InvalidArgumentException;

/**
 * Amounts are stored as integers in the currency's minor unit (cents), so
 * sums are exact. People type and read major units.
 */
final class Money
{
    /** Currencies without minor units; everything else has two. */
    private const ZERO_DECIMALS = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    /** The current organization's default currency. */
    public static function currency(): string
    {
        return app(CurrentOrganization::class)->get()->currency ?? 'USD';
    }

    public static function decimals(?string $currency = null): int
    {
        return in_array($currency ?? self::currency(), self::ZERO_DECIMALS, true) ? 0 : 2;
    }

    /**
     * "12.5" → 1250 (two decimals). Rejects more decimals than the currency has.
     */
    public static function toMinor(string $amount, ?string $currency = null): int
    {
        $decimals = self::decimals($currency);
        $amount = trim($amount);

        if (preg_match('/^\d{1,12}(?:\.(\d{1,'.max($decimals, 1).'}))?$/', $amount, $m) !== 1 || ($decimals === 0 && str_contains($amount, '.'))) {
            throw new InvalidArgumentException("Not an amount: {$amount}");
        }

        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return (int) $whole * (10 ** $decimals) + (int) str_pad($fraction, $decimals, '0');
    }

    /**
     * 1250 → "12.50"
     */
    public static function toMajor(int $minor, ?string $currency = null): string
    {
        $decimals = self::decimals($currency);

        return $decimals === 0 ? (string) $minor : number_format($minor / (10 ** $decimals), $decimals, '.', '');
    }

    /**
     * 125000 → "SSP 1,250.00", for messages.
     */
    public static function format(int $minor, ?string $currency = null): string
    {
        $currency ??= self::currency();
        $decimals = self::decimals($currency);

        return $currency.' '.number_format($minor / (10 ** $decimals), $decimals);
    }

    /**
     * An amount in another currency at an explicit rate ("units of `to`
     * per one unit of `from`"), in exact decimal arithmetic, rounded half
     * up to `to`'s minor unit. 100000 USD cents at "2450.5" → SSP minor.
     */
    public static function convert(int $minor, string $from, string $to, string $rate): int
    {
        if (! self::isRate($rate)) {
            throw new InvalidArgumentException("Not an exchange rate: {$rate}");
        }

        $major = bcdiv((string) $minor, bcpow('10', (string) self::decimals($from)), 12);
        $target = bcmul(bcmul($major, $rate, 12), bcpow('10', (string) self::decimals($to)), 12);

        return (int) bcadd($target, '0.5', 0);
    }

    /** An exchange rate as typed: up to 12 digits, up to 8 decimals. */
    public const RATE_PATTERN = '/^\d{1,12}(\.\d{1,8})?$/';

    /**
     * A usable exchange rate: written as RATE_PATTERN, and above zero.
     *
     * @phpstan-assert-if-true numeric-string $rate
     */
    public static function isRate(?string $rate): bool
    {
        return $rate !== null && is_numeric($rate) && preg_match(self::RATE_PATTERN, $rate) === 1 && bccomp($rate, '0', 8) > 0;
    }

    /**
     * Validation rule for an amount typed in major units.
     */
    public static function rule(?string $currency = null): string
    {
        return self::decimals($currency) === 0 ? 'regex:/^\d{1,12}$/' : 'regex:/^\d{1,12}(\.\d{1,2})?$/';
    }
}
