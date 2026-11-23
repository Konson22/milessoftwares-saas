<?php

namespace App\Procurement;

use App\Models\Workspace;
use Illuminate\Validation\ValidationException;
use Miles\PlatformClient\Money\EntryCurrencies;
use Miles\PlatformClient\Money\EntryCurrency;

/**
 * Amounts in Procurement: integers in a currency's minor units, quantities
 * as exact decimal strings. A document in another currency keeps the rate
 * it was entered at (workspace minor units for one unit of it, from the
 * workspace's entry currencies) and its total in the workspace currency;
 * comparisons and approval thresholds only ever use that total.
 */
final class Money
{
    public static function base(): string
    {
        return strtoupper(Workspace::settings()->currency);
    }

    public static function decimals(string $currency): int
    {
        return EntryCurrencies::decimalsOf($currency);
    }

    /**
     * The rate for a document currency: null for the workspace currency.
     *
     * @throws ValidationException when the workspace has no rate for it
     */
    public static function rate(string $currency, string $field = 'currency'): ?int
    {
        $currency = strtoupper($currency);
        if ($currency === self::base()) {
            return null;
        }

        $rate = EntryCurrency::query()->where('code', $currency)->value('rate_minor');
        if (! is_numeric($rate) || (int) $rate < 1) {
            throw ValidationException::withMessages([$field => "{$currency} has no exchange rate in this workspace. Add it under Settings → Currencies first."]);
        }

        return (int) $rate;
    }

    /**
     * An amount in a document currency, in workspace minor units (half up).
     */
    public static function toBase(int $minor, string $currency, ?int $rate): int
    {
        if ($rate === null) {
            return $minor;
        }

        $scale = 10 ** self::decimals($currency);

        return (int) bcdiv(bcadd(bcmul((string) $minor, (string) $rate), (string) intdiv($scale, 2)), (string) $scale, 0);
    }

    /**
     * A typed amount ("1,250.50") in minor units of the currency.
     *
     * @throws ValidationException
     */
    public static function parse(mixed $amount, string $currency, string $field): int
    {
        $decimals = self::decimals($currency);
        $text = str_replace(',', '', trim((string) $amount));
        $pattern = $decimals === 0 ? '/^\d{1,13}$/' : '/^\d{1,13}(?:\.\d{1,'.$decimals.'})?$/';

        if (preg_match($pattern, $text) !== 1) {
            throw ValidationException::withMessages([$field => 'Enter an amount in '.strtoupper($currency).($decimals === 0 ? ' without decimals.' : '.')]);
        }

        return (int) bcmul(self::numeric($text), (string) (10 ** $decimals), 0);
    }

    /**
     * quantity × unit price, rounded half up to the minor unit.
     */
    public static function lineAmount(string $quantity, int $unitPriceMinor): int
    {
        $exact = bcmul(self::quantity($quantity), (string) $unitPriceMinor, 3);

        return (int) bcadd($exact, '0.5', 0);
    }

    /**
     * A quantity normalised to three decimals ("2" → "2.000").
     *
     * @return numeric-string
     *
     * @throws ValidationException when it is not a plain decimal number
     */
    public static function quantity(mixed $quantity, string $field = 'quantity'): string
    {
        $text = str_replace(',', '', trim((string) $quantity));
        if (preg_match('/^-?\d{1,12}(?:\.\d+)?$/', $text) !== 1) {
            throw ValidationException::withMessages([$field => 'Enter a number.']);
        }

        return bcadd(self::numeric($text), '0', 3);
    }

    /**
     * @return numeric-string
     */
    private static function numeric(string $value): string
    {
        if (! is_numeric($value)) {
            throw new \InvalidArgumentException("Not a number: {$value}");
        }

        return $value;
    }

    /** "1250.50" from 125050 minor units. */
    public static function major(int $minor, string $currency): string
    {
        $decimals = self::decimals($currency);

        return $decimals === 0 ? (string) $minor : bcdiv((string) $minor, (string) (10 ** $decimals), $decimals);
    }

    /** "SSP 1,250.50", for audit details and messages. */
    public static function format(int $minor, string $currency): string
    {
        $decimals = self::decimals($currency);

        return strtoupper($currency).' '.number_format($minor / (10 ** $decimals), $decimals);
    }
}
