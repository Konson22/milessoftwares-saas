<?php

namespace App\Mel;

use InvalidArgumentException;

/**
 * Exact decimal arithmetic for indicator values (bcmath, 6 places, as the
 * numeric(20,6) columns). Floats never touch a stored value.
 */
final class Numbers
{
    public const SCALE = 6;

    /** Up to 14 digits before the point and 6 after, optionally negative. */
    public const PATTERN = '/^-?\d{1,14}(\.\d{1,6})?$/';

    /**
     * A user's or a spreadsheet's number as a canonical decimal string, or
     * null when empty or not a number. Thousands separators and spaces are
     * dropped ("1,250.5" → "1250.500000").
     */
    public static function parse(mixed $input): ?string
    {
        if ($input === null || $input === '') {
            return null;
        }

        if (is_int($input)) {
            $input = (string) $input;
        } elseif (is_float($input)) {
            if (! is_finite($input)) {
                return null;
            }
            $input = rtrim(rtrim(number_format($input, self::SCALE, '.', ''), '0'), '.');
        } elseif (! is_string($input)) {
            return null;
        }

        $clean = str_replace([',', ' ', "\u{00A0}"], '', trim($input));

        if (preg_match(self::PATTERN, $clean) !== 1) {
            return null;
        }

        return bcadd(self::n($clean), '0', self::SCALE);
    }

    public static function add(string $a, string $b): string
    {
        return bcadd(self::n($a), self::n($b), self::SCALE);
    }

    public static function sub(string $a, string $b): string
    {
        return bcsub(self::n($a), self::n($b), self::SCALE);
    }

    /**
     * a / b, or null when b is zero.
     */
    public static function div(string $a, string $b): ?string
    {
        return self::isZero($b) ? null : bcdiv(self::n($a), self::n($b), self::SCALE);
    }

    public static function mul(string $a, string $b): string
    {
        return bcmul(self::n($a), self::n($b), self::SCALE);
    }

    /**
     * numerator / denominator × scale (100 for a percentage), or null when
     * the denominator is zero.
     */
    public static function fraction(string $numerator, string $denominator, int $scale): ?string
    {
        if (self::isZero($denominator)) {
            return null;
        }

        // Multiply first so no precision is lost to the division.
        return bcdiv(bcmul(self::n($numerator), self::n((string) $scale), self::SCALE), self::n($denominator), self::SCALE);
    }

    /**
     * @param  list<string>  $values
     */
    public static function sum(array $values): string
    {
        return array_reduce($values, fn (string $carry, string $value) => self::add($carry, $value), '0');
    }

    public static function compare(string $a, string $b): int
    {
        return bccomp(self::n($a), self::n($b), self::SCALE);
    }

    public static function isZero(string $value): bool
    {
        return bccomp(self::n($value), '0', self::SCALE) === 0;
    }

    public static function isNegative(string $value): bool
    {
        return bccomp(self::n($value), '0', self::SCALE) < 0;
    }

    /**
     * Rounded half up to the given places, for display and exports.
     */
    public static function round(string $value, int $places): string
    {
        $places = max(0, min(self::SCALE, $places));
        $half = self::n('0.'.str_repeat('0', $places).'5');
        $rounded = self::isNegative($value) ? bcsub(self::n($value), $half, $places) : bcadd(self::n($value), $half, $places);

        // "-0" and "-0.00" read as zero.
        return self::isZero($rounded) ? bcadd('0', '0', $places) : $rounded;
    }

    /**
     * Drops trailing zeros: "12.500000" → "12.5", "3.000000" → "3".
     */
    public static function trim(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }

    /**
     * A stored or parsed decimal, checked: bcmath would otherwise fail with
     * a less helpful error on anything that is not a number.
     *
     * @return numeric-string
     */
    private static function n(string $value): string
    {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException("Not a decimal number: {$value}");
        }

        return $value;
    }
}
