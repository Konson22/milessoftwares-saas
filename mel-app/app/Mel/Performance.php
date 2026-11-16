<?php

namespace App\Mel;

/**
 * How one indicator did against its target in one period (ADR-0011 §4).
 * Per indicator only: nothing here combines different indicators.
 *
 * - increase: achievement = actual ÷ target. With a baseline, progress is
 *   also shown: how far from the baseline toward the target it moved.
 * - decrease: with a baseline above the target, achievement is that
 *   progress (baseline − actual) ÷ (baseline − target); without one,
 *   target ÷ actual (100 % or more once at or below the target).
 * - maintain: on track inside the target range, off track outside it.
 *
 * The status bands come from the workspace settings (90 % / 70 % by
 * default) and are named in the UI wherever a status is shown.
 */
final class Performance
{
    public const ON_TRACK = 'on_track';

    public const ATTENTION = 'needs_attention';

    public const OFF_TRACK = 'off_track';

    public const NO_TARGET = 'no_target';

    public const NO_DATA = 'no_data';

    /**
     * @return array{status: string, achievement: string|null, progress: string|null}
     */
    public static function assess(
        string $direction,
        ?string $actual,
        ?string $target,
        ?string $baseline = null,
        ?string $min = null,
        ?string $max = null,
        int $onTrack = 90,
        int $attention = 70,
    ): array {
        $hasTarget = $direction === 'maintain' ? ($min !== null || $max !== null) : $target !== null;

        if (! $hasTarget) {
            return ['status' => $actual === null ? self::NO_DATA : self::NO_TARGET, 'achievement' => null, 'progress' => null];
        }

        if ($actual === null) {
            return ['status' => self::NO_DATA, 'achievement' => null, 'progress' => null];
        }

        if ($direction === 'maintain') {
            $inside = ($min === null || Numbers::compare($actual, $min) >= 0) && ($max === null || Numbers::compare($actual, $max) <= 0);

            return ['status' => $inside ? self::ON_TRACK : self::OFF_TRACK, 'achievement' => null, 'progress' => null];
        }

        assert($target !== null);
        // Progress from a baseline only when the target lies beyond it in
        // the direction of improvement.
        $usable = $baseline !== null && Numbers::compare($target, $baseline) === ($direction === 'increase' ? 1 : -1);
        $progress = $usable ? self::progress($actual, $target, $baseline) : null;

        $achievement = $direction === 'increase'
            ? Numbers::fraction($actual, $target, 100)
            : ($progress ?? self::inverse($actual, $target));

        return [
            'status' => $achievement === null ? self::NO_TARGET : self::band($achievement, $onTrack, $attention),
            'achievement' => $achievement,
            'progress' => $progress,
        ];
    }

    /**
     * How far from the baseline toward the target, in percent; null
     * without a baseline or when baseline and target are equal.
     */
    public static function progress(string $actual, string $target, ?string $baseline): ?string
    {
        if ($baseline === null) {
            return null;
        }

        return Numbers::fraction(Numbers::sub($actual, $baseline), Numbers::sub($target, $baseline), 100);
    }

    /**
     * target ÷ actual for "decrease" indicators without a baseline.
     */
    private static function inverse(string $actual, string $target): ?string
    {
        if (Numbers::compare($actual, $target) <= 0) {
            return '100.000000';
        }

        return Numbers::fraction($target, $actual, 100);
    }

    public static function band(string $achievement, int $onTrack, int $attention): string
    {
        return match (true) {
            Numbers::compare($achievement, (string) $onTrack) >= 0 => self::ON_TRACK,
            Numbers::compare($achievement, (string) $attention) >= 0 => self::ATTENTION,
            default => self::OFF_TRACK,
        };
    }
}
