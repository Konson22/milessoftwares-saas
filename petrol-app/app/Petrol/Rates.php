<?php

namespace App\Petrol;

use App\Models\ExchangeRate;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Station exchange rates. A new rate closes the previous one. It does not
 * rewrite sales or expenses that already copied a rate.
 */
class Rates
{
    public function __construct(private readonly Audit $audit) {}

    public function set(string $branchId, string $base, string $quote, string $major, User $by): ExchangeRate
    {
        $base = strtoupper($base);
        $quote = strtoupper($quote);
        $known = app(Currencies::class)->acceptedCodes($branchId);
        if ($base === $quote || ! in_array($base, $known, true) || ! in_array($quote, $known, true)) {
            throw ValidationException::withMessages(['quote_currency' => 'Choose two different currencies this station accepts.']);
        }

        $books = Money::currency();
        if ($base !== $books && $quote !== $books) {
            throw ValidationException::withMessages(['base_currency' => 'One side of the rate must be the workspace currency ('.$books.').']);
        }

        try {
            $rateMinor = Fx::rateMinor($major, $quote);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['rate' => 'Enter the quote currency for one unit of the base currency.']);
        }

        return DB::transaction(function () use ($branchId, $base, $quote, $rateMinor, $by) {
            ExchangeRate::query()
                ->where('branch_id', $branchId)
                ->where('base_currency', $base)
                ->where('quote_currency', $quote)
                ->whereNull('effective_to')
                ->lockForUpdate()
                ->update(['effective_to' => now()]);

            $rate = ExchangeRate::query()->create([
                'branch_id' => $branchId,
                'base_currency' => $base,
                'quote_currency' => $quote,
                'rate_minor' => $rateMinor,
                'effective_from' => now(),
                'created_by' => $by->id,
            ]);

            $this->audit->record($by, 'rate.set', $rate, null, [
                'base' => $base,
                'quote' => $quote,
                'rate_minor' => $rateMinor,
            ], $branchId);

            return $rate;
        });
    }

    /**
     * @return array{currency: string, fx_base: ?string, fx_quote: ?string, fx_rate_minor: ?int, reporting_minor: int}
     */
    public function snapshot(string $branchId, string $currency, int $amountMinor): array
    {
        $books = Money::currency();
        $currency = strtoupper($currency);
        if ($currency === $books) {
            return [
                'currency' => $currency,
                'fx_base' => null,
                'fx_quote' => null,
                'fx_rate_minor' => null,
                'reporting_minor' => $amountMinor,
            ];
        }

        $rate = ExchangeRate::open($branchId, $currency, $books);
        if ($rate === null) {
            throw ValidationException::withMessages(['currency' => 'Set a '.$currency.' rate for this station first.']);
        }

        return [
            'currency' => $currency,
            'fx_base' => $rate->base_currency,
            'fx_quote' => $rate->quote_currency,
            'fx_rate_minor' => (int) $rate->rate_minor,
            'reporting_minor' => Fx::convert($amountMinor, $currency, $books, $rate),
        ];
    }

    /**
     * Reporting figures for a cash movement. Same-currency cash is stored
     * even when this station has no rate yet.
     *
     * @return array{currency: string, fx_base: ?string, fx_quote: ?string, fx_rate_minor: ?int, reporting_minor: ?int}
     */
    public function optionalSnapshot(string $branchId, string $currency, int $amountMinor): array
    {
        try {
            return $this->snapshot($branchId, $currency, $amountMinor);
        } catch (ValidationException) {
            return [
                'currency' => strtoupper($currency),
                'fx_base' => null,
                'fx_quote' => null,
                'fx_rate_minor' => null,
                'reporting_minor' => null,
            ];
        }
    }
}
