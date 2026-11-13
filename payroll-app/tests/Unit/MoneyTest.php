<?php

use App\Support\Money;

it('reads typed decimals without going through a float', function () {
    expect(Money::toMinor('0.29'))->toBe(29)
        ->and(Money::toMinor('1500.5'))->toBe(150_050)
        ->and(Money::toMinor('1500.50'))->toBe(150_050)
        ->and(Money::toMinor('.5'))->toBe(50)
        ->and(Money::toMinor('7.'))->toBe(700)
        ->and(Money::toMinor(12))->toBe(1200)
        ->and(Money::toMinor(0.29))->toBe(29)
        ->and(Money::toMinor('-3.05'))->toBe(-305)
        ->and(Money::toMinor('4.500'))->toBe(450)
        ->and(Money::toMinor(null))->toBe(0);
});

it('refuses anything but a decimal with up to two places', function (string $value) {
    expect(fn () => Money::hundredths($value))->toThrow(InvalidArgumentException::class);
})->with(['1.005', 'abc', '1e3', '.', '-', '1,000']);

it('writes hundredths back as a decimal', function () {
    expect(Money::fromHundredths(725))->toBe('7.25')
        ->and(Money::fromHundredths(5))->toBe('0.05')
        ->and(Money::fromHundredths(-1750))->toBe('-17.50');
});

it('shares an amount out in integers, rounding half up', function () {
    // 16 of 31 days of 31 000.00.
    expect(Money::share(3_100_000, 16, 31))->toBe(1_600_000)
        // 1 of 3 of 1.00 is 0.333..., 2 of 3 is 0.666...
        ->and(Money::share(100, 1, 3))->toBe(33)
        ->and(Money::share(100, 2, 3))->toBe(67)
        // Exactly half rounds away from zero.
        ->and(Money::share(1, 1, 2))->toBe(1)
        ->and(Money::share(-1, 1, 2))->toBe(-1)
        ->and(Money::share(500, 0, 31))->toBe(0);
});
