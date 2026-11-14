<?php

namespace App\Mel;

use App\Models\IndicatorVersion;

/**
 * Combines an indicator's values the way its definition says (ADR-0011 §4).
 *
 * A "figure" is what was reported for one unit and period: a value, or a
 * numerator and denominator for percentages, rates and ratios. Combining
 * figures follows the version's aggregation rule and never anything more:
 *
 * - sum: values added (counts, currency);
 * - recompute: numerators and denominators added, then divided, so 3/4 and
 *   1/100 make 4/104 (3.8 %), never the 37.5 % that averaging would give;
 * - latest, max, min: one of the figures, as reported;
 * - none: no combined figure.
 *
 * A combined figure built from incomplete parts (a fraction missing its
 * denominator) is marked incomplete and carries no value.
 */
final class Calculator
{
    /**
     * The figure's value in the indicator's own terms: the value, or the
     * fraction × scale (100 for a percentage). Null when it cannot be known.
     *
     * @param  array{value?: string|null, numerator?: string|null, denominator?: string|null}  $figure
     */
    public static function valueOf(IndicatorVersion $version, array $figure): ?string
    {
        if (! $version->isFraction()) {
            return $figure['value'] ?? null;
        }

        $numerator = $figure['numerator'] ?? null;
        $denominator = $figure['denominator'] ?? null;

        return $numerator === null || $denominator === null
            ? null
            : Numbers::fraction($numerator, $denominator, $version->scale());
    }

    /**
     * Combines the figures of several units in one period.
     *
     * @param  list<array{value?: string|null, numerator?: string|null, denominator?: string|null}>  $figures
     * @return array{value: string|null, numerator: string|null, denominator: string|null, complete: bool}|null null when the indicator is not combined across units
     */
    public static function acrossUnits(IndicatorVersion $version, array $figures): ?array
    {
        return self::combine($version, $version->unit_aggregation, Measures::unitAggregations($version->measure), $figures);
    }

    /**
     * Combines one unit's (or the programme's) figures over several
     * periods, in period order.
     *
     * @param  list<array{value?: string|null, numerator?: string|null, denominator?: string|null}>  $figures
     * @return array{value: string|null, numerator: string|null, denominator: string|null, complete: bool}|null null when the indicator is not combined over time
     */
    public static function overTime(IndicatorVersion $version, array $figures): ?array
    {
        return self::combine($version, $version->time_aggregation, Measures::timeAggregations($version->measure), $figures);
    }

    /**
     * @param  list<string>  $allowed  the rules the measure allows
     * @param  list<array{value?: string|null, numerator?: string|null, denominator?: string|null}>  $figures
     * @return array{value: string|null, numerator: string|null, denominator: string|null, complete: bool}|null
     */
    private static function combine(IndicatorVersion $version, string $rule, array $allowed, array $figures): ?array
    {
        if ($figures === []) {
            return ['value' => null, 'numerator' => null, 'denominator' => null, 'complete' => false];
        }

        // A rule the measure does not allow is never applied (a version
        // edited in the database, or rules tightened later).
        if ($rule === 'none' || ! in_array($rule, $allowed, true)) {
            return null;
        }

        return match ($rule) {
            'sum' => self::sum($figures),
            'recompute' => self::recompute($version, $figures),
            'latest' => self::pick($version, end($figures)),
            'max', 'min' => self::extreme($version, $figures, $rule),
            default => null,
        };
    }

    /**
     * @param  list<array{value?: string|null, numerator?: string|null, denominator?: string|null}>  $figures
     * @return array{value: string|null, numerator: string|null, denominator: string|null, complete: bool}
     */
    private static function sum(array $figures): array
    {
        $values = array_map(fn (array $figure) => $figure['value'] ?? null, $figures);
        $known = array_values(array_filter($values, fn (?string $value) => $value !== null));

        return [
            'value' => count($known) === count($values) ? Numbers::sum($known) : null,
            'numerator' => null,
            'denominator' => null,
            'complete' => count($known) === count($values),
        ];
    }

    /**
     * @param  list<array{value?: string|null, numerator?: string|null, denominator?: string|null}>  $figures
     * @return array{value: string|null, numerator: string|null, denominator: string|null, complete: bool}
     */
    private static function recompute(IndicatorVersion $version, array $figures): array
    {
        $numerators = [];
        $denominators = [];

        foreach ($figures as $figure) {
            if (($figure['numerator'] ?? null) === null || ($figure['denominator'] ?? null) === null) {
                return ['value' => null, 'numerator' => null, 'denominator' => null, 'complete' => false];
            }
            $numerators[] = $figure['numerator'];
            $denominators[] = $figure['denominator'];
        }

        $numerator = Numbers::sum($numerators);
        $denominator = Numbers::sum($denominators);

        return [
            'value' => Numbers::fraction($numerator, $denominator, $version->scale()),
            'numerator' => $numerator,
            'denominator' => $denominator,
            'complete' => true,
        ];
    }

    /**
     * @param  array{value?: string|null, numerator?: string|null, denominator?: string|null}  $figure
     * @return array{value: string|null, numerator: string|null, denominator: string|null, complete: bool}
     */
    private static function pick(IndicatorVersion $version, array $figure): array
    {
        $value = self::valueOf($version, $figure);

        return [
            'value' => $value,
            'numerator' => $figure['numerator'] ?? null,
            'denominator' => $figure['denominator'] ?? null,
            'complete' => $value !== null,
        ];
    }

    /**
     * @param  list<array{value?: string|null, numerator?: string|null, denominator?: string|null}>  $figures
     * @return array{value: string|null, numerator: string|null, denominator: string|null, complete: bool}
     */
    private static function extreme(IndicatorVersion $version, array $figures, string $rule): array
    {
        $best = null;
        $complete = true;

        foreach ($figures as $figure) {
            $value = self::valueOf($version, $figure);
            if ($value === null) {
                $complete = false;

                continue;
            }
            if ($best === null || ($rule === 'max' ? Numbers::compare($value, $best) > 0 : Numbers::compare($value, $best) < 0)) {
                $best = $value;
            }
        }

        return ['value' => $complete ? $best : null, 'numerator' => null, 'denominator' => null, 'complete' => $complete];
    }
}
