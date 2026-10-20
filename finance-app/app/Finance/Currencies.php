<?php

namespace App\Finance;

use App\Models\ExchangeRate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Miles\PlatformClient\Access\CurrentAccess;

/**
 * Other currencies than the books' (plan feature "multicurrency").
 *
 * A rate is the books-currency units for one unit of the currency, typed
 * by the organization and used from its day until the next one. Amounts
 * are converted exactly (bcmath) and rounded half away from zero to the
 * books' minor unit. The ledger always balances in the books' currency;
 * lines in another currency also keep that amount (see Ledger).
 */
class Currencies
{
    public const FEATURE = 'finance.multicurrency';

    /** Up to 14 digits, then up to 10 decimals; more than zero. */
    public const RATE_RULE = 'regex:/^(?=.*[1-9])\d{1,14}(\.\d{1,10})?$/';

    /**
     * Whether the organization's plan includes other currencies. Entries
     * in another currency are only offered then; existing ones stay.
     */
    public static function enabled(): bool
    {
        return app(CurrentAccess::class)->get()?->feature(self::FEATURE) !== null;
    }

    /**
     * A currency code other than the books'.
     *
     * @throws ValidationException
     */
    public static function check(string $currency, string $field = 'currency'): string
    {
        $currency = strtoupper(trim($currency));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw ValidationException::withMessages([$field => 'A currency is a three-letter code, such as USD.']);
        }
        if ($currency === Money::currency()) {
            throw ValidationException::withMessages([$field => "{$currency} is the books' own currency."]);
        }

        return $currency;
    }

    /**
     * The rate in force on the day.
     *
     * @throws ValidationException when there is none on or before it
     */
    public function rate(string $currency, CarbonImmutable $on, string $field = 'rate'): string
    {
        $rate = ExchangeRate::query()->where('currency', $currency)->whereDate('rate_on', '<=', $on)->orderByDesc('rate_on')->value('rate');

        if ($rate === null) {
            throw ValidationException::withMessages([$field => "No {$currency} rate on or before {$on->format('j M Y')}: add one under Currencies, or type the rate."]);
        }

        return self::normalize((string) $rate);
    }

    /**
     * Sets (or replaces) the rate for a day.
     */
    public function setRate(string $currency, CarbonImmutable $on, string $rate, User $by): ExchangeRate
    {
        $currency = self::check($currency);

        // By day (whereDate): a date column may be stored with a time.
        $exchangeRate = ExchangeRate::query()->where('currency', $currency)->whereDate('rate_on', $on)->first()
            ?? new ExchangeRate(['currency' => $currency, 'rate_on' => $on]);
        $exchangeRate->fill(['rate' => self::normalize($rate), 'created_by' => $by->id])->save();

        return $exchangeRate;
    }

    /**
     * An amount in the currency's minor unit, in the books' minor unit.
     */
    public static function toBooks(int $minor, string $currency, string $rate): int
    {
        if (! is_numeric($rate)) {
            throw new InvalidArgumentException("Not a rate: {$rate}");
        }
        $shift = Money::decimals() - Money::decimals($currency);
        $value = bcmul((string) $minor, $rate, 12);
        $value = match (true) {
            $shift > 0 => bcmul($value, bcpow('10', (string) $shift), 12),
            $shift < 0 => bcdiv($value, bcpow('10', (string) -$shift), 12),
            default => $value,
        };

        // Half away from zero: bcadd with scale 0 truncates toward zero.
        return (int) bcadd($value, str_starts_with($value, '-') ? '-0.5' : '0.5', 0);
    }

    /**
     * "4500.5000000000" → "4500.5".
     */
    public static function normalize(string $rate): string
    {
        $rate = trim($rate);

        return str_contains($rate, '.') ? rtrim(rtrim($rate, '0'), '.') : $rate;
    }
}
