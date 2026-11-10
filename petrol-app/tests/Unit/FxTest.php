<?php

use App\Models\ExchangeRate;
use App\Petrol\Fx;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

it('converts SSP and USD with integer half-up rounding', function () {
    $rate = new ExchangeRate([
        'base_currency' => 'USD',
        'quote_currency' => 'SSP',
        'rate_minor' => 780000,
    ]);

    expect(Fx::convert(100, 'USD', 'SSP', $rate))->toBe(780000)
        ->and(Fx::convert(780000, 'SSP', 'USD', $rate))->toBe(100)
        ->and(Fx::convert(1, 'USD', 'SSP', $rate))->toBe(7800);

    $rate->rate_minor = 780050;
    expect(Fx::convert(1, 'USD', 'SSP', $rate))->toBe(7801);
});

it('reads a typed amount in the currency it is in', function () {
    expect(Money::input('50000', 'UGX'))->toBe(50000)
        ->and(fn () => Money::input('1.5', 'UGX'))->toThrow(ValidationException::class)
        ->and(fn () => Money::input(['1'], 'UGX'))->toThrow(ValidationException::class);
});
