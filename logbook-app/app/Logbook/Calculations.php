<?php

namespace App\Logbook;

use App\Logbook\Models\Entry;
use App\Logbook\Models\FuelRate;
use App\Logbook\Models\Track;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * The fuel estimate of an activity (ADR-0030 §4.7, §4.8). Two fixed
 * formulas, no formula engine:
 *
 *   by distance: estimated litres = km × rate ÷ 100
 *   by hours:    estimated litres = hours × rate
 *
 * The rate is the one in force when the activity started. The fuel
 * measured, when the sheet records it, is the actual figure; a refill is
 * shown beside it and never used as the actual figure. The difference is
 * "difference from estimate", never a verdict.
 *
 * Calculated by the server at each submission and kept on the entry and
 * its revision; never recalculated afterwards.
 */
final class Calculations
{
    /**
     * The snapshot for an entry being submitted, and the meter columns.
     * Refuses readings that go backwards (unless a meter event explains
     * them) or that end below their start.
     *
     * @return array{calculation: array<string, mixed>|null, meter: array{meter_resource_id: string|null, meter_measure: string|null, meter_start: string|null, meter_end: string|null}}
     */
    public static function atSubmit(Entry $entry): array
    {
        $none = ['calculation' => null, 'meter' => ['meter_resource_id' => null, 'meter_measure' => null, 'meter_start' => null, 'meter_end' => null]];
        $activity = Recording::activity($entry->version);
        if ($activity === null) {
            return $none;
        }
        $values = $entry->values;
        $resourceId = is_string($values[$activity['resource_field']] ?? null) ? $values[$activity['resource_field']] : null;
        if ($resourceId === null) {
            return $none;
        }
        $started = $entry->activity_started_at ?? $entry->occurred_at;
        $ended = $entry->activity_ended_at;
        $number = fn (?string $field) => $field !== null && is_numeric($values[$field] ?? null) ? (float) $values[$field] : null;

        $flags = [];
        $meter = $none['meter'];
        if ($activity['measure'] === 'duration') {
            if ($ended === null) {
                return $none;
            }
            $start = null;
            $end = null;
            $quantity = round($started->diffInSeconds($ended) / 3600, 2);
            $source = 'recorded_duration';
        } else {
            $start = $number($activity['start_field']);
            $end = $number($activity['end_field']);
            if ($start === null || $end === null) {
                return $none;
            }
            if ($end < $start) {
                throw ValidationException::withMessages(["values.{$activity['end_field']}" => 'The end reading is below the start reading.']);
            }
            self::checkSequence($entry, $resourceId, $activity, $start, $started);
            $overlaps = self::overlaps($entry, $resourceId, $activity['measure'], $start, $end, $started);
            if ($overlaps !== []) {
                $flags[] = 'overlap';
            }
            $quantity = round($end - $start, 1);
            $source = $activity['measure'];
            $meter = [
                'meter_resource_id' => $resourceId, 'meter_measure' => $activity['measure'],
                'meter_start' => number_format($start, 1, '.', ''), 'meter_end' => number_format($end, 1, '.', ''),
            ];
        }

        $rate = Fuel::rateAt($resourceId, $started);
        $method = $activity['measure'] === 'odometer' ? 'distance' : 'hours';
        $estimated = null;
        if ($rate === null) {
            $flags[] = 'no_rate';
        } elseif ($rate->method !== $method) {
            // A vehicle rated by distance logged by hours (or the reverse): nothing to multiply.
            $flags[] = 'rate_method_mismatch';
        } else {
            $estimated = round($method === 'distance' ? $quantity * (float) $rate->rate / 100 : $quantity * (float) $rate->rate, 2);
        }
        $actual = $number($activity['actual_field']);
        $refill = $number($activity['refill_field']);
        $variance = $estimated !== null && $actual !== null ? round($actual - $estimated, 2) : null;

        // What the phone showed offline, kept as evidence; a different rate is flagged, never applied.
        $phone = is_array($entry->calculation['phone'] ?? null) ? $entry->calculation['phone'] : null;
        $phoneRate = is_string($phone['rate_id'] ?? null) ? $phone['rate_id'] : null;
        if ($phoneRate !== null && $phoneRate !== $rate?->id) {
            $flags[] = 'rate_changed';
        }

        return [
            'calculation' => [
                'method' => $method,
                'measure' => $activity['measure'],
                'resource_id' => $resourceId,
                'inputs' => ['start' => $start, 'end' => $end, 'started_at' => $started->toIso8601String(), 'ended_at' => $ended?->toIso8601String()],
                'quantity' => $quantity,
                'unit' => $method === 'distance' ? 'km' : 'h',
                'quantity_source' => $source,
                'rate' => $rate === null ? null : self::rateSnapshot($rate),
                'estimated_l' => $estimated,
                'actual_l' => $actual,
                'refill_l' => $refill,
                'variance_l' => $variance,
                'variance_pct' => $variance !== null && $estimated > 0 ? round($variance / $estimated * 100, 1) : null,
                'gps_km' => self::gpsKm($entry),
                'overlaps' => $overlaps ?? [],
                'flags' => $flags,
                'phone' => $phone,
                'calculated_at' => now()->toIso8601String(),
                'by' => 'server',
            ],
            'meter' => $meter,
        ];
    }

    /** @return array{id: string, value: float, unit: string, fuel_type: string, effective_from: string} */
    public static function rateSnapshot(FuelRate $rate): array
    {
        return ['id' => $rate->id, 'value' => (float) $rate->rate, 'unit' => $rate->unit(), 'fuel_type' => $rate->fuel_type, 'effective_from' => $rate->effective_from->toIso8601String()];
    }

    /**
     * The start reading may not be below the last end reading of the
     * same resource and meter, unless a meter event since then says the
     * meter now reads less.
     *
     * @param  array<string, string|null>  $activity
     */
    private static function checkSequence(Entry $entry, string $resourceId, array $activity, float $start, CarbonImmutable $started): void
    {
        $previous = self::recorded($entry, $resourceId, $activity['measure'])
            ->where('activity_started_at', '<=', $started)
            ->orderByDesc('activity_started_at')->orderByDesc('meter_end')
            ->first();
        if ($previous === null || $start >= (float) $previous->meter_end) {
            return;
        }
        $event = Fuel::meterEventBefore($resourceId, (string) $activity['measure'], $started);
        if ($event !== null && $event->occurred_at->greaterThanOrEqualTo($previous->activity_started_at) && $start >= (float) $event->reading) {
            return;
        }
        $what = $activity['measure'] === 'odometer' ? 'odometer' : 'hour meter';
        throw ValidationException::withMessages([
            "values.{$activity['start_field']}" => "The start reading {$start} is below the last end reading ".((float) $previous->meter_end)
                .' ('.($previous->reference ?? 'an earlier entry').", {$previous->activity_started_at->toDateString()}). If the {$what} was replaced or reset, a manager records it on the resource first.",
        ]);
    }

    /**
     * Recorded activities whose readings overlap these, since the last
     * meter event (a new meter starts its own numbers).
     *
     * @return list<array{id: string, reference: string|null}>
     */
    private static function overlaps(Entry $entry, string $resourceId, string $measure, float $start, float $end, CarbonImmutable $started): array
    {
        $event = Fuel::meterEventBefore($resourceId, $measure, $started);

        return array_values(self::recorded($entry, $resourceId, $measure)
            ->where('meter_start', '<', $end)->where('meter_end', '>', $start)
            ->when($event !== null, fn ($q) => $q->where('activity_started_at', '>=', $event->occurred_at))
            ->limit(10)->get(['id', 'reference'])
            ->map(fn (Entry $e) => ['id' => $e->id, 'reference' => $e->reference])->all());
    }

    /** @return Builder<Entry> */
    private static function recorded(Entry $entry, string $resourceId, string $measure)
    {
        return Entry::query()->whereKeyNot($entry->id)->where('status', '!=', 'draft')
            ->where('meter_resource_id', $resourceId)->where('meter_measure', $measure);
    }

    private static function gpsKm(Entry $entry): ?float
    {
        if ($entry->log_track_id === null) {
            return null;
        }
        $metres = Track::query()->whereKey($entry->log_track_id)->value('distance_m');

        return $metres === null ? null : round($metres / 1000, 1);
    }
}
