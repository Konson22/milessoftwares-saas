<?php

namespace App\Support;

use App\Models\Currency;
use App\Models\Workspace;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Miles\PlatformClient\Tenancy\CurrentTenant;

/**
 * Amounts are stored as integers in the currency's minor unit (cents), as
 * the Finance contracts require. People type and read major units.
 */
final class Money
{
    /** Currencies without minor units; everything else has two. */
    private const ZERO_DECIMALS = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    public static function currency(): string
    {
        $currency = Workspace::query()->value('currency');

        return is_string($currency) && $currency !== '' ? $currency : 'USD';
    }

    public static function decimals(?string $currency = null): int
    {
        $code = strtoupper($currency ?? self::currency());
        if (in_array($code, self::ZERO_DECIMALS, true)) {
            return 0;
        }
        if (app(CurrentTenant::class)->has()) {
            $stored = Currency::query()->where('code', $code)->value('decimals');
            if ($stored !== null) {
                return (int) $stored;
            }
        }

        return 2;
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
     * A typed amount in the given currency, or a validation error on $field.
     * Use with anyRule() when the currency is only known from the request.
     */
    public static function input(mixed $amount, ?string $currency, string $field = 'amount'): int
    {
        if (! is_string($amount) && ! is_int($amount)) {
            throw ValidationException::withMessages([$field => 'Enter an amount.']);
        }
        $currency = $currency === null || $currency === '' ? null : strtoupper($currency);
        try {
            return self::toMinor((string) $amount, $currency);
        } catch (InvalidArgumentException) {
            $decimals = self::decimals($currency);

            throw ValidationException::withMessages([
                $field => $decimals === 0
                    ? 'Enter a whole '.($currency ?? self::currency()).' amount.'
                    : 'Enter an amount with at most '.$decimals.' decimals.',
            ]);
        }
    }

    /**
     * Shape check for an amount whose currency is checked later by input().
     */
    public static function anyRule(): string
    {
        return 'regex:/^\d{1,12}(\.\d{1,4})?$/';
    }

    /**
     * Validation rule for an amount typed in major units.
     */
    public static function rule(?string $currency = null): string
    {
        return self::decimals($currency) === 0 ? 'regex:/^\d{1,12}$/' : 'regex:/^\d{1,12}(\.\d{1,2})?$/';
    }
}
