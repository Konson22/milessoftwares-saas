<?php

namespace Miles\PlatformClient\Money;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Miles\PlatformClient\Tenancy\CurrentTenant;

/**
 * The currencies people may type amounts in. The workspace currency is
 * always one of them; the others carry a rate set by the workspace. An
 * amount in another currency is converted when it is entered and the
 * record keeps the workspace figure, so totals and reports add up.
 */
final class EntryCurrencies
{
    /** Currencies without minor units; everything else has two. */
    public const ZERO_DECIMALS = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    public function __construct(private readonly CurrentTenant $tenant) {}

    /** The workspace currency: every product keeps it on its workspaces row. */
    public function base(): string
    {
        $code = DB::table('workspaces')->where('organization_id', $this->tenant->require()->id)->value('currency');

        return is_string($code) && $code !== '' ? strtoupper($code) : 'USD';
    }

    public static function decimalsOf(string $code): int
    {
        return in_array(strtoupper($code), self::ZERO_DECIMALS, true) ? 0 : 2;
    }

    /**
     * For pages: the workspace currency first, then the others by code.
     *
     * @return array{base: string, currencies: list<array{code: string, decimals: int, rate: string|null, updatedAt: string|null}>}
     */
    public function forPage(): array
    {
        $base = $this->base();
        $rows = [[
            'code' => $base,
            'decimals' => self::decimalsOf($base),
            'rate' => null,
            'updatedAt' => null,
        ]];

        foreach (EntryCurrency::query()->where('code', '!=', $base)->orderBy('code')->get() as $currency) {
            $rows[] = [
                'code' => $currency->code,
                'decimals' => (int) $currency->decimals,
                'rate' => self::major((int) $currency->rate_minor, self::decimalsOf($base)),
                'updatedAt' => $currency->updated_at?->toIso8601String(),
            ];
        }

        return ['base' => $base, 'currencies' => $rows];
    }

    /**
     * Adds a currency or changes its rate: workspace currency for one unit.
     */
    public function set(string $code, string $rate, ?string $by = null): EntryCurrency
    {
        $code = strtoupper(trim($code));
        $base = $this->base();

        if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            throw ValidationException::withMessages(['code' => 'Use a three-letter currency code, like USD.']);
        }
        if ($code === $base) {
            throw ValidationException::withMessages(['code' => $code.' is the workspace currency already.']);
        }

        $rateMinor = self::parse($rate, self::decimalsOf($base));
        if ($rateMinor === null || $rateMinor < 1) {
            throw ValidationException::withMessages(['rate' => 'Enter how much '.$base.' one '.$code.' is worth.']);
        }

        $currency = EntryCurrency::query()->firstOrNew(['code' => $code]);
        $currency->forceFill([
            'decimals' => self::decimalsOf($code),
            'rate_minor' => $rateMinor,
            'updated_by' => $by,
        ])->save();

        return $currency;
    }

    public function remove(string $code): void
    {
        EntryCurrency::query()->where('code', strtoupper($code))->delete();
    }

    /**
     * Reads a typed amount in a currency (blank: the workspace currency) and
     * converts it. Errors are reported on $field.
     *
     * @throws ValidationException
     */
    public function enter(string $amount, ?string $currency, string $field): EnteredAmount
    {
        $base = $this->base();
        $code = strtoupper(trim((string) $currency));
        $code = $code === '' ? $base : $code;

        if ($code === $base) {
            $minor = self::parse($amount, self::decimalsOf($base));
            if ($minor === null) {
                throw ValidationException::withMessages([$field => 'Enter an amount in '.$base.'.']);
            }

            return new EnteredAmount($base, $minor, null, $base, $minor);
        }

        $row = EntryCurrency::query()->where('code', $code)->first();
        if ($row === null) {
            throw ValidationException::withMessages([$field => $code.' has no rate in this workspace. Add it under currencies first.']);
        }

        $decimals = (int) $row->decimals;
        $minor = self::parse($amount, $decimals);
        if ($minor === null) {
            throw ValidationException::withMessages([$field => 'Enter an amount in '.$code.($decimals === 0 ? ' without decimals.' : '.')]);
        }

        $scale = 10 ** $decimals;
        $rate = (int) $row->rate_minor;
        if ($minor > 0 && $minor > intdiv(PHP_INT_MAX - intdiv($scale, 2), $rate)) {
            throw ValidationException::withMessages([$field => 'This amount is too large to convert.']);
        }

        return new EnteredAmount($code, $minor, $rate, $base, intdiv($minor * $rate + intdiv($scale, 2), $scale));
    }

    /**
     * Same as enter() for an optional amount.
     */
    public function enterOptional(?string $amount, ?string $currency, string $field): ?EnteredAmount
    {
        return $amount === null || trim($amount) === '' ? null : $this->enter($amount, $currency, $field);
    }

    /**
     * Keeps what was typed next to the record that stores the converted
     * amount. Nothing is kept for the workspace currency.
     */
    public function remember(Model $subject, string $field, ?EnteredAmount $amount, ?string $by = null): void
    {
        if ($amount === null || ! $amount->foreign()) {
            return;
        }

        EnteredAmountRecord::query()->create([
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => (string) $subject->getKey(),
            'field' => $field,
            'currency' => $amount->currency,
            'amount_minor' => $amount->amountMinor,
            'rate_minor' => (int) $amount->rateMinor,
            'base_currency' => $amount->baseCurrency,
            'base_minor' => $amount->baseMinor,
            'created_by' => $by,
        ]);
    }

    /**
     * What was typed in another currency on a record, by field.
     *
     * @return array<string, array{currency: string, amount: string, rate: string, base: string}>
     */
    public function enteredOn(Model $subject): array
    {
        $rows = EnteredAmountRecord::query()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', (string) $subject->getKey())
            ->orderBy('created_at')
            ->get();

        $entered = [];
        foreach ($rows as $row) {
            $baseDecimals = self::decimalsOf($row->base_currency);
            $entered[$row->field] = [
                'currency' => $row->currency,
                'amount' => self::major($row->amount_minor, self::decimalsOf($row->currency)),
                'rate' => self::major($row->rate_minor, $baseDecimals),
                'base' => self::major($row->base_minor, $baseDecimals),
            ];
        }

        return $entered;
    }

    private static function parse(string $amount, int $decimals): ?int
    {
        $amount = str_replace(',', '', trim($amount));
        $pattern = $decimals === 0 ? '/^\d{1,15}$/' : '/^\d{1,15}(?:\.\d{1,'.$decimals.'})?$/';
        if (preg_match($pattern, $amount) !== 1) {
            return null;
        }

        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return (int) $whole * (10 ** $decimals) + (int) str_pad($fraction, $decimals, '0');
    }

    private static function major(int $minor, int $decimals): string
    {
        if ($decimals === 0) {
            return (string) $minor;
        }

        $scale = 10 ** $decimals;
        $sign = $minor < 0 ? '-' : '';
        $minor = abs($minor);

        return $sign.intdiv($minor, $scale).'.'.str_pad((string) ($minor % $scale), $decimals, '0', STR_PAD_LEFT);
    }
}
