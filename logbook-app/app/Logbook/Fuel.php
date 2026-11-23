<?php

namespace App\Logbook;

use App\Logbook\Models\FuelRate;
use App\Logbook\Models\LogResource;
use App\Logbook\Models\MeterEvent;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fuel rates and meter events of resources (ADR-0030 §4.6, §4.8). Kept by
 * managers, audited, append-only. Entries are calculated in phase 5 with
 * the rate in force when the activity happened; nothing here recalculates
 * what was already recorded.
 */
final class Fuel
{
    public const LABELS = [
        'distance' => 'By distance (L/100 km)',
        'hours' => 'By running hours (L/h)',
        'odometer' => 'Odometer (km)',
        'hour_meter' => 'Hour meter (h)',
        'replaced' => 'Meter replaced',
        'reset' => 'Meter reset',
        'corrected' => 'Reading corrected',
    ];

    /**
     * Adds a rate from a moment on. A rate with the same start as an
     * earlier one supersedes it (the later row wins).
     *
     * @param  array<string, mixed>  $data  fuel_type, method, rate, effective_from (local "Y-m-d\TH:i"), note
     */
    public static function addRate(LogResource $resource, array $data, User $by): FuelRate
    {
        $errors = [];
        if (! in_array($data['fuel_type'] ?? null, FuelRate::FUELS, true)) {
            $errors['fuel_type'] = 'Choose diesel, petrol or other.';
        }
        if (! in_array($data['method'] ?? null, FuelRate::METHODS, true)) {
            $errors['method'] = 'Choose by distance or by running hours.';
        }
        $rate = is_numeric($data['rate'] ?? null) ? round((float) $data['rate'], 3) : null;
        if ($rate === null || $rate <= 0 || $rate > 10000) {
            $errors['rate'] = 'Give a rate above 0 (litres per 100 km, or per hour).';
        }
        $from = self::moment($data['effective_from'] ?? null);
        if ($from === null) {
            $errors['effective_from'] = 'Give the date and time the rate applies from.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($resource, $data, $rate, $from, $by) {
            $before = self::rateAt($resource->id, $from);
            $row = FuelRate::query()->create([
                'log_resource_id' => $resource->id,
                'fuel_type' => $data['fuel_type'],
                'method' => $data['method'],
                'rate' => number_format((float) $rate, 3, '.', ''),
                'effective_from' => $from,
                'note' => self::text($data['note'] ?? null),
                'created_by' => $by->id,
            ]);
            AuditTrail::record('resource.fuel_rate_added', $resource, $by, [
                'rate_id' => $row->id, 'method' => $row->method, 'fuel_type' => $row->fuel_type, 'rate' => $row->rate,
                'effective_from' => $from->toIso8601String(), 'replaces' => $before?->id,
            ]);

            return $row;
        });
    }

    /** The rate in force at a moment: the latest that started at or before it. */
    public static function rateAt(string $resourceId, CarbonImmutable $at): ?FuelRate
    {
        return FuelRate::query()->where('log_resource_id', $resourceId)
            ->where('effective_from', '<=', $at)
            ->orderByDesc('effective_from')->orderByDesc('created_at')->orderByDesc('id')
            ->first();
    }

    /**
     * Records a replaced, reset or corrected meter, with a reason.
     *
     * @param  array<string, mixed>  $data  measure, kind, reading, occurred_at (local), reason
     */
    public static function addMeterEvent(LogResource $resource, array $data, User $by): MeterEvent
    {
        $errors = [];
        if (! in_array($data['measure'] ?? null, MeterEvent::MEASURES, true)) {
            $errors['measure'] = 'Choose the odometer or the hour meter.';
        }
        if (! in_array($data['kind'] ?? null, MeterEvent::KINDS, true)) {
            $errors['kind'] = 'Choose replaced, reset or corrected.';
        }
        $reading = is_numeric($data['reading'] ?? null) ? round((float) $data['reading'], 1) : null;
        if ($reading === null || $reading < 0 || $reading > 99999999) {
            $errors['reading'] = 'Give the reading the meter shows now (0 or more).';
        }
        $at = self::moment($data['occurred_at'] ?? null);
        if ($at === null) {
            $errors['occurred_at'] = 'Give when it happened.';
        } elseif ($at->isAfter(now()->addMinutes(5))) {
            $errors['occurred_at'] = 'It cannot be in the future.';
        }
        $reason = self::text($data['reason'] ?? null);
        if ($reason === null) {
            $errors['reason'] = 'Say why, so reviewers can follow the readings.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($resource, $data, $reading, $at, $reason, $by) {
            $event = MeterEvent::query()->create([
                'log_resource_id' => $resource->id,
                'measure' => $data['measure'],
                'kind' => $data['kind'],
                'reading' => number_format((float) $reading, 1, '.', ''),
                'occurred_at' => $at,
                'reason' => $reason,
                'created_by' => $by->id,
            ]);
            AuditTrail::record('resource.meter_event', $resource, $by, [
                'event_id' => $event->id, 'measure' => $event->measure, 'kind' => $event->kind,
                'reading' => $event->reading, 'occurred_at' => $at->toIso8601String(), 'reason' => $reason,
            ]);

            return $event;
        });
    }

    /** The latest meter event of a measure at or before a moment. */
    public static function meterEventBefore(string $resourceId, string $measure, CarbonImmutable $at): ?MeterEvent
    {
        return MeterEvent::query()->where('log_resource_id', $resourceId)->where('measure', $measure)
            ->where('occurred_at', '<=', $at)
            ->orderByDesc('occurred_at')->orderByDesc('created_at')->orderByDesc('id')
            ->first();
    }

    /** A local "Y-m-d\TH:i" (the workspace's time zone) or an ISO time, as UTC. */
    private static function moment(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            $tz = Workspace::current()->timezone ?? 'UTC';
            $hasZone = (bool) preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $value);

            return ($hasZone ? CarbonImmutable::parse($value) : CarbonImmutable::parse($value, $tz))->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function text(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : mb_substr($value, 0, 500);
    }
}
