<?php

namespace App\Logbook;

use App\Logbook\Models\Entry;
use App\Logbook\Models\LogSheetVersion;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The points a vehicle trip stopped at (ADR-0032 §5), a fixed list on the
 * trip entry: place, arrival, departure, odometer, purpose, note and an
 * optional GPS pin. Shape is checked whenever the entry is saved; at
 * submission the stops must sit inside the trip: times between its start
 * and end and in order, odometer between its start and end readings and
 * never going down.
 *
 * Times are kept as UTC ISO strings; the web form sends workspace-local
 * times, the phone sends ISO times with their offset.
 */
final class Stops
{
    public const SETTINGS = ['off', 'optional', 'required'];

    public const LABELS = ['off' => 'No stops', 'optional' => 'Stops may be recorded', 'required' => 'At least one stop'];

    public const MAX = 50;

    /** off, optional or required, as the version says. */
    public static function setting(?LogSheetVersion $version): string
    {
        $value = $version?->recording['stops'] ?? 'off';

        return in_array($value, self::SETTINGS, true) ? $value : 'off';
    }

    /**
     * The stops sent, checked for shape and normalised. Null or [] clears.
     *
     * @return list<array{place: string, arrived_at: string, departed_at: string|null, odometer: float|null, purpose: string|null, note: string|null, pin: array{lat: float, lng: float, accuracy_m: float|null}|null}>
     */
    public static function input(mixed $raw, bool $localTimes): array
    {
        if ($raw === null || $raw === []) {
            return [];
        }
        if (! is_array($raw) || ! array_is_list($raw)) {
            throw ValidationException::withMessages(['stops' => 'The stops are not a list.']);
        }
        if (count($raw) > self::MAX) {
            throw ValidationException::withMessages(['stops' => 'A trip holds at most '.self::MAX.' stops.']);
        }
        $tz = Workspace::current()->timezone ?? 'UTC';
        $errors = [];
        $out = [];
        foreach ($raw as $i => $stop) {
            $at = "stops.{$i}";
            if (! is_array($stop)) {
                $errors[$at] = 'This stop is not readable.';

                continue;
            }
            $text = fn (string $key, int $max) => is_string($stop[$key] ?? null) && trim($stop[$key]) !== '' ? mb_substr(trim($stop[$key]), 0, $max) : null;
            $time = function (string $key) use ($stop, $localTimes, $tz): array {
                $value = $stop[$key] ?? null;
                if ($value === null || $value === '') {
                    return [null, false];
                }
                if (! is_string($value)) {
                    return [null, true];
                }
                try {
                    return [CarbonImmutable::parse($value, $localTimes ? $tz : 'UTC')->utc()->toIso8601String(), false];
                } catch (Throwable) {
                    return [null, true];
                }
            };
            $place = $text('place', 200);
            [$arrived, $badArrived] = $time('arrived_at');
            [$departed, $badDeparted] = $time('departed_at');
            $odometer = $stop['odometer'] ?? null;
            if ($place === null) {
                $errors["{$at}.place"] = 'Name the place.';
            }
            if ($arrived === null) {
                $errors["{$at}.arrived_at"] = $badArrived ? 'Give the arrival as a date and time.' : 'Give the arrival time.';
            }
            if ($badDeparted) {
                $errors["{$at}.departed_at"] = 'Give the departure as a date and time.';
            } elseif ($arrived !== null && $departed !== null && $departed < $arrived) {
                $errors["{$at}.departed_at"] = 'The departure is before the arrival.';
            }
            if ($odometer !== null && $odometer !== '' && (! is_numeric($odometer) || (float) $odometer < 0)) {
                $errors["{$at}.odometer"] = 'Give the odometer as a number.';
            }
            $pin = null;
            if (is_array($stop['pin'] ?? null)) {
                $lat = $stop['pin']['lat'] ?? null;
                $lng = $stop['pin']['lng'] ?? null;
                if (! is_numeric($lat) || ! is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180) {
                    $errors["{$at}.pin"] = 'The location of this stop is not valid.';
                } else {
                    $acc = $stop['pin']['accuracy_m'] ?? null;
                    $pin = ['lat' => round((float) $lat, 7), 'lng' => round((float) $lng, 7), 'accuracy_m' => is_numeric($acc) ? round((float) $acc, 1) : null];
                }
            }
            $out[] = [
                'place' => (string) $place,
                'arrived_at' => (string) $arrived,
                'departed_at' => $departed,
                'odometer' => is_numeric($odometer) ? round((float) $odometer, 1) : null,
                'purpose' => $text('purpose', 200),
                'note' => $text('note', 500),
                'pin' => $pin,
            ];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }

    /**
     * At submission: the stops the sheet asks for, inside the trip.
     *
     * @param  array<string, mixed>  $values
     */
    public static function check(Entry $entry, array $values): void
    {
        $setting = self::setting($entry->version);
        $stops = $entry->stops ?? [];
        if ($setting === 'off') {
            return;
        }
        if ($setting === 'required' && $stops === []) {
            throw ValidationException::withMessages(['stops' => 'Record at least one stop of this trip.']);
        }
        $tz = Workspace::current()->timezone ?? 'UTC';
        $local = fn (string $t) => CarbonImmutable::parse($t)->setTimezone($tz)->format('H:i');
        $start = $entry->activity_started_at ?? $entry->occurred_at;
        $end = $entry->activity_ended_at;
        $activity = Recording::activity($entry->version);
        $reading = fn (?string $field) => $field !== null && is_numeric($values[$field] ?? null) ? (float) $values[$field] : null;
        $odoStart = $activity !== null && $activity['measure'] === 'odometer' ? $reading($activity['start_field']) : null;
        $odoEnd = $activity !== null && $activity['measure'] === 'odometer' ? $reading($activity['end_field']) : null;

        $errors = [];
        $lastTime = null;
        $lastOdo = $odoStart;
        foreach ($stops as $i => $stop) {
            $at = "stops.{$i}";
            $arrived = CarbonImmutable::parse($stop['arrived_at']);
            $departed = $stop['departed_at'] !== null ? CarbonImmutable::parse($stop['departed_at']) : null;
            if ($arrived->lessThan($start)) {
                $errors["{$at}.arrived_at"] = 'This stop is before the trip started ('.$local($start->toIso8601String()).').';
            } elseif ($end !== null && ($departed ?? $arrived)->greaterThan($end)) {
                $errors["{$at}.arrived_at"] = 'This stop is after the trip ended ('.$local($end->toIso8601String()).').';
            } elseif ($lastTime !== null && $arrived->lessThan($lastTime)) {
                $errors["{$at}.arrived_at"] = 'Stops go in the order they happened: this one is before the previous stop.';
            }
            $lastTime = $departed ?? $arrived;
            $odo = $stop['odometer'];
            if ($odo !== null) {
                if ($lastOdo !== null && $odo < $lastOdo) {
                    $errors["{$at}.odometer"] = 'The odometer is below the previous reading ('.self::number($lastOdo).').';
                } elseif ($odoEnd !== null && $odo > $odoEnd) {
                    $errors["{$at}.odometer"] = 'The odometer is above the reading at the end of the trip ('.self::number($odoEnd).').';
                }
                $lastOdo = $odo;
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Stops for display, in workspace time, with the distance from the
     * previous point (the trip's start reading for the first).
     *
     * @param  list<array<string, mixed>>|null  $stops
     * @return list<array<string, mixed>>
     */
    public static function display(?array $stops, ?float $startReading = null): array
    {
        $tz = Workspace::current()->timezone ?? 'UTC';
        $local = fn (?string $t) => $t === null ? null : CarbonImmutable::parse($t)->setTimezone($tz)->format('Y-m-d H:i');
        $last = $startReading;
        $out = [];
        foreach ($stops ?? [] as $s) {
            $odo = is_numeric($s['odometer'] ?? null) ? (float) $s['odometer'] : null;
            $out[] = [
                'place' => $s['place'], 'arrivedAt' => $local($s['arrived_at']), 'departedAt' => $local($s['departed_at'] ?? null),
                'odometer' => $odo, 'legKm' => $odo !== null && $last !== null ? round($odo - $last, 1) : null,
                'purpose' => $s['purpose'] ?? null, 'note' => $s['note'] ?? null, 'pin' => $s['pin'] ?? null,
                'minutes' => isset($s['departed_at']) ? (int) CarbonImmutable::parse($s['arrived_at'])->diffInMinutes(CarbonImmutable::parse($s['departed_at'])) : null,
            ];
            $last = $odo ?? $last;
        }

        return $out;
    }

    /**
     * Stops as the web form edits them: workspace-local times.
     *
     * @param  list<array<string, mixed>>|null  $stops
     * @return list<array<string, mixed>>
     */
    public static function forForm(?array $stops): array
    {
        $tz = Workspace::current()->timezone ?? 'UTC';
        $local = fn (?string $t) => $t === null ? '' : CarbonImmutable::parse($t)->setTimezone($tz)->format('Y-m-d\TH:i');

        return array_map(fn (array $s) => [
            'place' => $s['place'], 'arrived_at' => $local($s['arrived_at']), 'departed_at' => $local($s['departed_at'] ?? null),
            'odometer' => $s['odometer'] === null ? '' : self::number((float) $s['odometer']),
            'purpose' => $s['purpose'] ?? '', 'note' => $s['note'] ?? '', 'pin' => $s['pin'] ?? null,
        ], $stops ?? []);
    }

    private static function number(float $n): string
    {
        return rtrim(rtrim(number_format($n, 1, '.', ''), '0'), '.');
    }
}
