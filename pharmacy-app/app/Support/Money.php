<?php

namespace App\Support;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Miles\PlatformClient\Money\EnteredAmount;
use Miles\PlatformClient\Money\EntryCurrencies;

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
        return Workspace::current()->currency ?? 'USD';
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
     * An amount from validated input with the currency it was typed in
     * ("{field}_currency", blank for the workspace currency), converted to
     * the workspace currency. Errors land on $field.
     *
     * @param  array<string, mixed>  $input
     */
    public static function entered(array $input, string $field, ?string $currency = null): EnteredAmount
    {
        return app(EntryCurrencies::class)->enter((string) $input[$field], $currency ?? ($input[$field.'_currency'] ?? null), $field);
    }

    /**
     * One typed amount in a currency (blank: the workspace currency), for
     * amounts inside lists, where the error goes on "lines.3.unit_cost".
     */
    public static function enter(string $amount, ?string $currency, string $field): EnteredAmount
    {
        return app(EntryCurrencies::class)->enter($amount, $currency, $field);
    }

    /**
     * Each line's amount in one document currency, keyed like the lines.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, EnteredAmount>
     */
    public static function enterLines(array $lines, string $field, ?string $currency, string $prefix = 'lines'): array
    {
        $entered = [];
        foreach ($lines as $index => $line) {
            $entered[$index] = self::enter((string) $line[$field], $currency, "{$prefix}.{$index}.{$field}");
        }

        return $entered;
    }

    /**
     * Keeps each line's amount when the document was typed in another currency.
     *
     * @param  array<int, EnteredAmount>  $amounts
     */
    public static function rememberLines(Model $document, string $field, array $amounts, ?string $by = null, string $prefix = 'lines'): void
    {
        foreach (array_values($amounts) as $index => $amount) {
            self::remember($document, "{$prefix}.{$index}.{$field}", $amount, $by);
        }
    }

    /**
     * Same as entered() for an amount that may be left blank.
     *
     * @param  array<string, mixed>  $input
     */
    public static function enteredOptional(array $input, string $field, ?string $currency = null): ?EnteredAmount
    {
        $value = $input[$field] ?? null;

        return $value === null || trim((string) $value) === '' ? null : self::entered($input, $field, $currency);
    }

    /**
     * Keeps what was typed in another currency next to the saved record.
     */
    public static function remember(Model $subject, string $field, ?EnteredAmount $amount, ?string $by = null): void
    {
        app(EntryCurrencies::class)->remember($subject, $field, $amount, $by);
    }

    /**
     * Validation rule for the currency an amount is typed in.
     *
     * @return list<string>
     */
    public static function currencyRule(): array
    {
        return ['nullable', 'string', 'size:3'];
    }

    /**
     * Validation rule for an amount typed in major units.
     */
    public static function rule(?string $currency = null): string
    {
        return self::decimals($currency) === 0 ? 'regex:/^\d{1,12}$/' : 'regex:/^\d{1,12}(\.\d{1,2})?$/';
    }
}
