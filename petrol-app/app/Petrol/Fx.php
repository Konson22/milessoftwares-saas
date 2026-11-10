<?php

namespace App\Petrol;

use App\Models\ExchangeRate;
use App\Support\Money;
use InvalidArgumentException;

/**
 * Converts minor units with an integer rate. The rate is quote-currency
 * minor units for one major unit of the base currency (7800.00 SSP per
 * 1 USD is 780000 when SSP has two decimals).
 */
final class Fx
{
    public const CURRENCIES = ['SSP', 'USD'];

    public static function rateMinor(string $majorPerUnit, string $quoteCurrency): int
    {
        $rate = Money::toMinor($majorPerUnit, $quoteCurrency);
        if ($rate < 1) {
            throw new InvalidArgumentException('Enter an exchange rate.');
        }

        return $rate;
    }

    public static function convert(int $amountMinor, string $from, string $to, ExchangeRate $rate): int
    {
        if ($from === $to) {
            return $amountMinor;
        }

        if ($from === $rate->base_currency && $to === $rate->quote_currency) {
            return self::baseToQuote($amountMinor, (int) $rate->rate_minor, $rate->base_currency);
        }

        if ($from === $rate->quote_currency && $to === $rate->base_currency) {
            return self::quoteToBase($amountMinor, (int) $rate->rate_minor, $rate->base_currency);
        }

        throw new InvalidArgumentException('This rate does not convert '.$from.' to '.$to.'.');
    }

    public static function baseToQuote(int $amountMinor, int $rateMinor, string $baseCurrency): int
    {
        $scale = 10 ** Money::decimals($baseCurrency);
        self::guard($amountMinor, $rateMinor, $scale);

        return intdiv($amountMinor * $rateMinor + intdiv($scale, 2), $scale);
    }

    public static function quoteToBase(int $amountMinor, int $rateMinor, string $baseCurrency): int
    {
        if ($rateMinor < 1) {
            throw new InvalidArgumentException('Enter an exchange rate.');
        }

        $scale = 10 ** Money::decimals($baseCurrency);
        self::guard($amountMinor, $scale, $rateMinor);

        return intdiv($amountMinor * $scale + intdiv($rateMinor, 2), $rateMinor);
    }

    private static function guard(int $left, int $right, int $divisor): void
    {
        if ($left < 0 || $right < 1 || $divisor < 1) {
            throw new InvalidArgumentException('Amount cannot be converted.');
        }

        if ($left > intdiv(PHP_INT_MAX - intdiv($divisor, 2), $right)) {
            throw new InvalidArgumentException('Amount is too large to convert.');
        }
    }
}
