<?php

namespace App\Logbook;

use App\Logbook\Models\TrackEvent;

/**
 * What a recorded route says (ADR-0028 §4), worked out from the points as
 * observed. Pure: the same rules run on the phone (src/logbook/route.ts).
 *
 * - A point less accurate than MAX_ACCURACY_M is kept but not used.
 * - A jump faster than MAX_SPEED_MPS from the last used point is an
 *   implausible reading: kept, not used.
 * - Steps under MIN_STEP_M are jitter: not distance.
 * - More than GAP_SECONDS between two used points, or a break the phone
 *   logged (paused, interrupted, GPS off, permission taken away), ends the
 *   line. No distance is counted across it: a straight line over a gap is
 *   not the road travelled. Pauses are not gaps; the others are.
 */
final class Route
{
    public const MAX_ACCURACY_M = 75.0;

    public const MAX_SPEED_MPS = 70.0;

    public const MIN_STEP_M = 5.0;

    public const GAP_SECONDS = 300;

    /** Below this a step is standing still, not moving time. */
    public const MOVING_SPEED_MPS = 0.5;

    /**
     * @param  list<array{seq: int, lat: float, lng: float, accuracy_m: float|null, t: int}>  $points  t: unix seconds
     * @param  list<array{seq: int, kind: string, t: int}>  $events
     * @return array{
     *     distance_m: int, moving_seconds: int, used: int, rejected: int, gaps_count: int, gap_seconds: int,
     *     bounds: array{south: float, west: float, north: float, east: float}|null,
     *     segments: list<list<array{0: float, 1: float, 2: int, 3: float|null}>>,
     *     gaps: list<array{from: array{0: float, 1: float, 2: int}, to: array{0: float, 1: float, 2: int}, seconds: int, reason: string}>
     * }
     */
    public static function summarize(array $points, array $events): array
    {
        $stream = [];
        foreach ($points as $p) {
            $stream[] = ['seq' => $p['seq'], 'point' => $p];
        }
        foreach ($events as $e) {
            $stream[] = ['seq' => $e['seq'], 'event' => $e];
        }
        usort($stream, fn ($a, $b) => $a['seq'] <=> $b['seq']);

        $distance = 0.0;
        $moving = 0;
        $used = 0;
        $rejected = 0;
        $gaps = [];
        $segments = [];
        /** @var list<array{float, float, int, float|null}> $current */
        $current = [];
        $prev = null;
        /** A break the line must not cross: [reason, time] or null. */
        $break = null;
        $bounds = null;

        $close = function () use (&$segments, &$current) {
            if ($current !== []) {
                $segments[] = $current;
            }
            $current = [];
        };

        foreach ($stream as $row) {
            if (isset($row['event'])) {
                $kind = $row['event']['kind'];
                if (in_array($kind, TrackEvent::BREAKS, true)) {
                    $close();
                    // A pause is the person's choice, not missing data.
                    $break = $kind === 'pause' || $kind === 'end' ? ['pause', $row['event']['t']] : [$kind, $row['event']['t']];
                }

                continue;
            }

            $p = $row['point'];
            if ($p['accuracy_m'] !== null && $p['accuracy_m'] > self::MAX_ACCURACY_M) {
                $rejected++;

                continue;
            }
            if ($prev !== null) {
                $dt = $p['t'] - $prev['t'];
                if ($dt <= 0) {
                    $rejected++;

                    continue;
                }
                $d = self::metres($prev['lat'], $prev['lng'], $p['lat'], $p['lng']);
                $gapReason = $break !== null && $break[0] !== 'pause' ? $break[0] : ($dt > self::GAP_SECONDS && $break === null ? 'no_signal' : null);
                if ($break === null && $dt <= self::GAP_SECONDS && $d / $dt > self::MAX_SPEED_MPS) {
                    $rejected++;

                    continue;
                }
                if ($gapReason !== null) {
                    $close();
                    $gaps[] = [
                        'from' => [$prev['lat'], $prev['lng'], $prev['t']],
                        'to' => [$p['lat'], $p['lng'], $p['t']],
                        'seconds' => $dt,
                        'reason' => $gapReason,
                    ];
                } elseif ($break === null && $d >= self::MIN_STEP_M) {
                    $distance += $d;
                    if ($d / $dt >= self::MOVING_SPEED_MPS) {
                        $moving += $dt;
                    }
                } elseif ($break === null) {
                    // Jitter: stay on the last point, so slow real movement still adds up.
                    $used++;
                    $current[] = [$p['lat'], $p['lng'], $p['t'], $p['accuracy_m']];
                    $bounds = self::extend($bounds, $p['lat'], $p['lng']);

                    continue;
                }
            }

            $break = null;
            $used++;
            $current[] = [$p['lat'], $p['lng'], $p['t'], $p['accuracy_m']];
            $bounds = self::extend($bounds, $p['lat'], $p['lng']);
            $prev = $p;
        }
        $close();

        return [
            'distance_m' => (int) round($distance),
            'moving_seconds' => $moving,
            'used' => $used,
            'rejected' => $rejected,
            'gaps_count' => count($gaps),
            'gap_seconds' => array_sum(array_column($gaps, 'seconds')),
            'bounds' => $bounds,
            'segments' => $segments,
            'gaps' => $gaps,
        ];
    }

    /** Great-circle distance in metres. */
    public static function metres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371008.8;
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $dp = $p2 - $p1;
        $dl = deg2rad($lng2 - $lng1);
        $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }

    /**
     * @param  array{south: float, west: float, north: float, east: float}|null  $b
     * @return array{south: float, west: float, north: float, east: float}
     */
    private static function extend(?array $b, float $lat, float $lng): array
    {
        return $b === null
            ? ['south' => $lat, 'west' => $lng, 'north' => $lat, 'east' => $lng]
            : ['south' => min($b['south'], $lat), 'west' => min($b['west'], $lng), 'north' => max($b['north'], $lat), 'east' => max($b['east'], $lng)];
    }
}
