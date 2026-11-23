<?php

namespace App\Logbook;

use App\Logbook\Models\Entry;
use App\Logbook\Models\LogException;
use App\Logbook\Models\LogResource;
use App\Logbook\Models\Shift;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Activities and fuel in reports (ADR-0030 §4.9). Built from the
 * calculation each entry kept at its last submission: one figure per
 * entry, so an entry is never counted twice and instantaneous readings
 * (a tank level) are never added up. Estimated, measured and refilled
 * litres stay three separate totals; the difference is only given where
 * both an estimate and a measurement exist.
 */
final class FuelReports
{
    /** Entries summarised at most; past this the page says so. */
    public const LIMIT = 20000;

    public const GROUPS = ['resource', 'operator', 'shift', 'month'];

    /**
     * Recorded activities in the filtered scope: entries with a
     * calculation, or with an activity start and end.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Entry>
     */
    public static function query(array $filters): Builder
    {
        return Reports::query($filters)->where(fn (Builder $q) => $q->whereNotNull('calculation')->orWhereNotNull('activity_ended_at'));
    }

    /**
     * Totals by resource, operator, shift and month, with the overall
     * line.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public static function summary(array $filters): array
    {
        $tz = Workspace::current()->timezone ?? 'UTC';
        $query = self::query($filters);
        $count = (clone $query)->count();
        $groups = array_fill_keys(self::GROUPS, []);
        $total = self::blank();

        (clone $query)->reorder()->orderBy('id')->limit(self::LIMIT)
            ->select(['id', 'organization_id', 'calculation', 'created_by', 'shift_id', 'activity_started_at', 'activity_ended_at', 'occurred_at'])
            ->chunk(1000, function ($entries) use (&$groups, &$total, $tz) {
                foreach ($entries as $e) {
                    /** @var Entry $e */
                    $c = is_array($e->calculation) && isset($e->calculation['method']) ? $e->calculation : null;
                    $keys = [
                        'resource' => $c['resource_id'] ?? null,
                        'operator' => $e->created_by,
                        'shift' => $e->shift_id,
                        'month' => ($e->activity_started_at ?? $e->occurred_at)->setTimezone($tz)->format('Y-m'),
                    ];
                    foreach ($keys as $group => $key) {
                        $groups[$group][$key ?? ''] = self::add($groups[$group][$key ?? ''] ?? self::blank(), $c, $e);
                    }
                    $total = self::add($total, $c, $e);
                }
            });

        $labels = self::labels($groups);

        return [
            'count' => $count,
            'limited' => $count > self::LIMIT,
            'limit' => self::LIMIT,
            'total' => self::finish($total),
            'groups' => collect($groups)->map(fn (array $rows, string $group) => collect($rows)
                ->map(fn (array $row, string $key) => ['key' => $key, 'label' => $labels[$group][$key] ?? ($key === '' ? self::none($group) : $key), ...self::finish($row)])
                ->sortBy(fn (array $row) => $group === 'month' ? $row['key'] : -($row['estimated_l'] + $row['entries'] / 1e6))
                ->values()->all())->all(),
        ];
    }

    /** @return array<string, mixed> */
    private static function blank(): array
    {
        return ['entries' => 0, 'km' => 0.0, 'h' => 0.0, 'estimated_l' => 0.0, 'actual_l' => 0.0, 'refill_l' => 0.0,
            'compared' => 0, 'compared_estimated_l' => 0.0, 'compared_actual_l' => 0.0, 'without_estimate' => 0, 'flagged' => 0];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>|null  $c
     * @return array<string, mixed>
     */
    private static function add(array $row, ?array $c, Entry $e): array
    {
        $row['entries']++;
        if ($c === null) {
            // An activity on a sheet without a fuel estimate: its duration only.
            if ($e->activity_started_at !== null && $e->activity_ended_at !== null) {
                $row['h'] += $e->activity_started_at->diffInSeconds($e->activity_ended_at) / 3600;
            }
            $row['without_estimate']++;

            return $row;
        }
        $row[$c['unit'] === 'km' ? 'km' : 'h'] += (float) ($c['quantity'] ?? 0);
        $estimated = $c['estimated_l'] ?? null;
        $actual = $c['actual_l'] ?? null;
        $row['estimated_l'] += (float) ($estimated ?? 0);
        $row['actual_l'] += (float) ($actual ?? 0);
        $row['refill_l'] += (float) ($c['refill_l'] ?? 0);
        if ($estimated === null) {
            $row['without_estimate']++;
        }
        // The difference only over entries that have both figures.
        if ($estimated !== null && $actual !== null) {
            $row['compared']++;
            $row['compared_estimated_l'] += (float) $estimated;
            $row['compared_actual_l'] += (float) $actual;
        }
        if (($c['flags'] ?? []) !== [] && array_diff($c['flags'], ['no_rate']) !== []) {
            $row['flagged']++;
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function finish(array $row): array
    {
        $variance = $row['compared'] > 0 ? round($row['compared_actual_l'] - $row['compared_estimated_l'], 2) : null;

        return [
            'entries' => $row['entries'],
            'km' => round($row['km'], 1),
            'h' => round($row['h'], 2),
            'estimated_l' => round($row['estimated_l'], 2),
            'actual_l' => round($row['actual_l'], 2),
            'refill_l' => round($row['refill_l'], 2),
            'compared' => $row['compared'],
            'variance_l' => $variance,
            'variance_pct' => $variance !== null && $row['compared_estimated_l'] > 0 ? round($variance / $row['compared_estimated_l'] * 100, 1) : null,
            'without_estimate' => $row['without_estimate'],
            'flagged' => $row['flagged'],
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $groups
     * @return array<string, array<string, string>>
     */
    private static function labels(array $groups): array
    {
        $ids = fn (string $g) => array_values(array_filter(array_map('strval', array_keys($groups[$g])), fn (string $k) => $k !== ''));

        return [
            'resource' => LogResource::query()->whereIn('id', $ids('resource'))->get()->mapWithKeys(fn (LogResource $r) => [$r->id => $r->label()])->all(),
            'operator' => User::query()->whereIn('id', $ids('operator'))->pluck('name', 'id')->all(),
            'shift' => Shift::query()->whereIn('id', $ids('shift'))->get()->mapWithKeys(fn (Shift $s) => [$s->id => $s->label()])->all(),
            'month' => collect($ids('month'))->mapWithKeys(fn (string $m) => [$m => CarbonImmutable::createFromFormat('Y-m', $m)?->format('M Y') ?? $m])->all(),
        ];
    }

    private static function none(string $group): string
    {
        return match ($group) {
            'resource' => 'No resource or no estimate',
            'shift' => 'No shift',
            default => '—',
        };
    }

    /**
     * One activity as a table row.
     *
     * @param  array<string, string>  $resources  id => label
     * @param  array<string, int>  $exceptions  entry id => count
     * @return array<string, mixed>
     */
    public static function row(Entry $e, array $resources, array $exceptions): array
    {
        $c = is_array($e->calculation) && isset($e->calculation['method']) ? $e->calculation : null;
        $started = $e->activity_started_at ?? $e->occurred_at;

        return [
            'id' => $e->id,
            'reference' => $e->reference,
            'sheet' => $e->sheet->code.' · '.$e->sheet->name,
            'resource' => $c !== null ? ($resources[$c['resource_id']] ?? null) : null,
            'operator' => $e->creator->name,
            'shift' => $e->shift?->name,
            'startedAt' => $started->toIso8601String(),
            'endedAt' => $e->activity_ended_at?->toIso8601String(),
            'durationH' => $e->activity_ended_at !== null ? round($started->diffInSeconds($e->activity_ended_at) / 3600, 2) : null,
            'quantity' => $c['quantity'] ?? null,
            'unit' => $c['unit'] ?? null,
            'estimatedL' => $c['estimated_l'] ?? null,
            'actualL' => $c['actual_l'] ?? null,
            'varianceL' => $c['variance_l'] ?? null,
            'variancePct' => $c['variance_pct'] ?? null,
            'refillL' => $c['refill_l'] ?? null,
            'gpsKm' => $c['gps_km'] ?? null,
            'flags' => $c['flags'] ?? [],
            'exceptions' => $exceptions[$e->id] ?? 0,
            'track' => $e->log_track_id,
            'status' => $e->status,
        ];
    }

    /**
     * Resource labels and open-or-closed exception counts for a page of
     * entries.
     *
     * @param  iterable<Entry>  $entries
     * @return array{0: array<string, string>, 1: array<string, int>}
     */
    public static function lookups(iterable $entries): array
    {
        $resourceIds = [];
        $entryIds = [];
        foreach ($entries as $e) {
            $entryIds[] = $e->id;
            if (is_string($e->calculation['resource_id'] ?? null)) {
                $resourceIds[] = $e->calculation['resource_id'];
            }
        }
        $resources = LogResource::query()->whereIn('id', array_unique($resourceIds))->get()->mapWithKeys(fn (LogResource $r) => [$r->id => $r->label()])->all();
        $exceptions = Exceptions::visible()->whereIn('log_entry_id', $entryIds)->selectRaw('log_entry_id, count(*) as n')->groupBy('log_entry_id')
            ->get()->mapWithKeys(fn (LogException $x) => [(string) $x->log_entry_id => (int) $x->getAttribute('n')])->all();

        return [$resources, $exceptions];
    }
}
