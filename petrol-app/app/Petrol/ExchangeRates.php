<?php

namespace App\Petrol;

use App\Models\ExchangeRate;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Station rates. A new rate closes the previous one. It does not rewrite sales.
 */
class ExchangeRates
{
    public function __construct(private readonly Audit $audit) {}

    public function set(string $branchId, string $base, string $quote, string $major, User $by): ExchangeRate
    {
        $base = strtoupper($base);
        $quote = strtoupper($quote);
        if ($base === $quote || ! in_array($base, Fx::CURRENCIES, true) || ! in_array($quote, Fx::CURRENCIES, true)) {
            throw ValidationException::withMessages(['quote_currency' => 'Choose SSP and USD, one each.']);
        }

        $reporting = Money::currency();
        if ($base !== $reporting && $quote !== $reporting) {
            throw ValidationException::withMessages(['base_currency' => 'One side of the rate must be the workspace currency ('.$reporting.').']);
        }

        try {
            $rateMinor = Fx::rateMinor($major, $quote);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['rate' => 'Enter the quote currency for one unit of the base currency.']);
        }

        return DB::transaction(function () use ($branchId, $base, $quote, $rateMinor, $by) {
            $current = ExchangeRate::query()
                ->where('branch_id', $branchId)
                ->where('base_currency', $base)
                ->where('quote_currency', $quote)
                ->whereNull('effective_to')
                ->lockForUpdate()
                ->first();

            if ($current !== null) {
                $current->effective_to = now();
                $current->save();
            }

            $rate = ExchangeRate::query()->create([
                'branch_id' => $branchId,
                'base_currency' => $base,
                'quote_currency' => $quote,
                'rate_minor' => $rateMinor,
                'effective_from' => now(),
                'created_by' => $by->id,
            ]);

            $this->audit->record($by, 'rate.set', $rate, [
                'rate_minor' => $current?->rate_minor,
            ], [
                'base' => $base,
                'quote' => $quote,
                'rate_minor' => $rateMinor,
            ], $branchId);

            return $rate;
        });
    }
}
