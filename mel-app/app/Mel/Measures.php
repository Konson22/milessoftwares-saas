<?php

namespace App\Mel;

/**
 * What each kind of measure allows (ADR-0011 §4). Nothing is summed or
 * averaged unless the measure makes that meaningful:
 *
 * - count, currency: totals add up.
 * - number (scores, averages): never added; latest, highest or lowest only.
 * - percentage, rate, ratio: kept as numerator and denominator; combined
 *   values are recomputed from the summed parts, never by averaging.
 * - yes_no: 1 or 0; never combined.
 */
final class Measures
{
    public const ALL = ['count', 'number', 'percentage', 'rate', 'ratio', 'currency', 'yes_no'];

    public const FRACTIONS = ['percentage', 'rate', 'ratio'];

    public const TYPES = ['input', 'process', 'output', 'outcome', 'impact'];

    public const DIRECTIONS = ['increase', 'decrease', 'maintain'];

    public const BREAKDOWN_RULES = ['must_equal_total', 'at_most_total', 'none'];

    public static function isFraction(string $measure): bool
    {
        return in_array($measure, self::FRACTIONS, true);
    }

    /**
     * How values over several periods may be combined.
     *
     * @return list<string>
     */
    public static function timeAggregations(string $measure): array
    {
        return match ($measure) {
            'count', 'currency' => ['sum', 'latest', 'max', 'none'],
            'number' => ['latest', 'max', 'min', 'none'],
            'percentage', 'rate', 'ratio' => ['recompute', 'latest', 'none'],
            default => ['latest', 'none'],
        };
    }

    /**
     * How values of several units in one period may be combined.
     *
     * @return list<string>
     */
    public static function unitAggregations(string $measure): array
    {
        return match ($measure) {
            'count', 'currency' => ['sum', 'none'],
            'percentage', 'rate', 'ratio' => ['recompute', 'none'],
            default => ['none'],
        };
    }

    /**
     * Breakdown rules that make sense for the measure: cells of a fraction
     * or a score never add up to the total.
     *
     * @return list<string>
     */
    public static function breakdownRules(string $measure): array
    {
        return in_array($measure, ['count', 'currency'], true) ? self::BREAKDOWN_RULES : ['none'];
    }

    /**
     * The scale a fraction is shown in: 100 for a percentage, the version's
     * multiplier for a rate (per 1,000), 1 for a ratio.
     */
    public static function scale(string $measure, ?int $rateMultiplier): int
    {
        return match ($measure) {
            'percentage' => 100,
            'rate' => max(1, $rateMultiplier ?? 1),
            default => 1,
        };
    }
}
