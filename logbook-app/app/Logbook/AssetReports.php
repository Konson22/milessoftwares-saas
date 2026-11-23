<?php

namespace App\Logbook;

use App\Logbook\Models\Entry;
use App\Logbook\Models\Location;
use App\Logbook\Models\LogException;
use App\Logbook\Models\LogResource;
use App\Logbook\Models\LogSheet;
use App\Logbook\Models\LogSheetVersion;
use App\Logbook\Models\ScheduleOccurrence;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reports by asset (ADR-0031 §11), built on the same filters and the same
 * entry query as every other report view:
 *
 * - assets(): one row per asset — records, readings out of range, open
 *   exceptions, expected and missed checks, fuel estimated — each linking
 *   back to the filtered records;
 * - measurements(): for one sheet, each number field per asset: count,
 *   minimum, maximum, average, and a total only for readings that add up
 *   (never a total of temperatures or tank levels);
 * - compliance(): expected readings by sheet and asset: on time, late,
 *   missed, excused, awaiting review. Submission timeliness stays apart
 *   from review: an entry in on time is on time however long review takes.
 */
final class AssetReports
{
    public const ASSET_LIMIT = 500;

    /**
     * The assets the filters select (asset, category, kind, location).
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<LogResource>
     */
    public static function assetQuery(array $filters): Builder
    {
        return LogResource::query()
            ->when($filters['resource'] ?? null, fn (Builder $q, $id) => $q->whereKey($id))
            ->when($filters['category'] ?? null, fn (Builder $q, $type) => $q->whereRaw('lower(type) = ?', [mb_strtolower((string) $type)]))
            ->when($filters['kind'] ?? null, fn (Builder $q, $kind) => $q->where('kind', $kind))
            ->when($filters['location'] ?? null, fn (Builder $q, $id) => $q->whereIn('location_id', self::placesIn((string) $id)))
            // Retired assets only when asked for by name: their history stays reachable.
            ->when(empty($filters['resource']), fn (Builder $q) => $q->where('status', '!=', 'retired'));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{rows: list<array<string, mixed>>, truncated: bool}
     */
    public static function assets(array $filters): array
    {
        $assets = self::assetQuery($filters)->with('location.parent')->orderBy('code')->limit(self::ASSET_LIMIT + 1)->get();
        $truncated = $assets->count() > self::ASSET_LIMIT;
        $assets = $assets->take(self::ASSET_LIMIT);
        $ids = $assets->pluck('id')->all();
        if ($ids === []) {
            return ['rows' => [], 'truncated' => false];
        }
        // Entry filters, minus the asset ones (applied through resource_id here).
        $entryFilters = array_diff_key($filters, array_flip(['resource', 'category', 'kind', 'location']));

        $stats = array_fill_keys($ids, ['records' => 0, 'last' => null, 'outOfRange' => 0, 'fuel' => 0.0, 'fuelCount' => 0]);
        Reports::query($entryFilters)->whereIn('resource_id', $ids)->reorder()
            ->with('version:id,fields')->select(['id', 'resource_id', 'occurred_at', 'values', 'log_sheet_version_id', 'calculation'])
            ->limit(Reports::SUMMARY_LIMIT)->cursor()
            ->each(function (Entry $e) use (&$stats) {
                $s = &$stats[(string) $e->resource_id];
                $s['records']++;
                $s['last'] = $s['last'] === null || $e->occurred_at->gt($s['last']) ? $e->occurred_at : $s['last'];
                $s['outOfRange'] += count(Fields::deviations($e->version->fields, $e->values ?? []));
                if (is_numeric($e->calculation['estimated_l'] ?? null)) {
                    $s['fuel'] += (float) $e->calculation['estimated_l'];
                    $s['fuelCount']++;
                }
            });

        $exceptions = Exceptions::visible()->whereIn('resource_id', $ids)->whereIn('status', LogException::OPEN)
            ->toBase()->selectRaw('resource_id, count(*) as n')->groupBy('resource_id')->pluck('n', 'resource_id');
        $checks = [];
        foreach (self::occurrences($entryFilters)->whereIn('resource_id', $ids)->get(['resource_id', 'status', 'log_entry_id', 'timeliness', 'due_at']) as $o) {
            $c = &$checks[(string) $o->resource_id];
            $c ??= ['expected' => 0, 'missed' => 0, 'onTime' => 0];
            if ($o->status !== 'excused' && $o->due_at->lte(CarbonImmutable::now())) {
                $c['expected']++;
            }
            $c['missed'] += $o->status === 'missed' ? 1 : 0;
            $c['onTime'] += $o->timeliness === 'on_time' ? 1 : 0;
            unset($c);
        }
        $tz = Workspace::current()->timezone ?? 'UTC';

        return ['truncated' => $truncated, 'rows' => $assets->map(fn (LogResource $r) => [
            'id' => $r->id, 'label' => $r->label(), 'type' => $r->type, 'kind' => $r->kind, 'status' => $r->status, 'location' => $r->location?->path(),
            'records' => $stats[$r->id]['records'],
            'lastAt' => $stats[$r->id]['last']?->setTimezone($tz)->format('Y-m-d H:i'),
            'outOfRange' => $stats[$r->id]['outOfRange'],
            'openExceptions' => (int) ($exceptions[$r->id] ?? 0),
            'expected' => $checks[$r->id]['expected'] ?? 0,
            'onTime' => $checks[$r->id]['onTime'] ?? 0,
            'missed' => $checks[$r->id]['missed'] ?? 0,
            'fuelL' => $stats[$r->id]['fuelCount'] > 0 ? round($stats[$r->id]['fuel'], 1) : null,
        ])->values()->all()];
    }

    /**
     * One sheet's number fields, per asset. Totals only where readings
     * add up (Fields::summable).
     *
     * @param  array<string, mixed>  $filters
     * @return list<array{resource: string|null, label: string, fields: list<array<string, mixed>>}>|null
     */
    public static function measurements(LogSheet $sheet, array $filters): ?array
    {
        $numeric = [];
        foreach (LogSheetVersion::query()->where('log_sheet_id', $sheet->id)->where('status', 'published')->orderBy('version')->get() as $version) {
            foreach ($version->fields as $field) {
                if (in_array($field['type'], Fields::NUMERIC, true)) {
                    $numeric[$field['name']] = $field;
                }
            }
        }
        if ($numeric === []) {
            return null;
        }
        $assetIds = isset($filters['category']) || isset($filters['kind']) || isset($filters['location']) || isset($filters['resource'])
            ? self::assetQuery($filters)->pluck('id')->all() : null;
        $entryFilters = array_diff_key($filters, array_flip(['resource', 'category', 'kind', 'location']));
        $blank = fn () => array_map(fn () => ['count' => 0, 'sum' => 0.0, 'min' => null, 'max' => null, 'out' => 0], $numeric);
        $groups = [];
        Reports::query([...$entryFilters, 'sheet' => $sheet->id])->reorder()
            ->when($assetIds !== null, fn (Builder $q) => $q->whereIn('resource_id', $assetIds))
            ->with('version:id,fields')->select(['id', 'resource_id', 'values', 'log_sheet_version_id'])->limit(Reports::SUMMARY_LIMIT)
            ->cursor()->each(function (Entry $e) use (&$groups, $blank) {
                $key = (string) ($e->resource_id ?? '');
                $groups[$key] ??= $blank();
                $out = array_column(Fields::deviations($e->version->fields, $e->values ?? []), null, 'name');
                foreach ($groups[$key] as $name => &$s) {
                    $v = $e->values[$name] ?? null;
                    if (! is_numeric($v)) {
                        continue;
                    }
                    $v = (float) $v;
                    $s['count']++;
                    $s['sum'] += $v;
                    $s['min'] = $s['min'] === null ? $v : min($s['min'], $v);
                    $s['max'] = $s['max'] === null ? $v : max($s['max'], $v);
                    $s['out'] += isset($out[$name]) ? 1 : 0;
                }
            });
        $labels = LogResource::query()->whereIn('id', array_filter(array_keys($groups)))->get()->mapWithKeys(fn (LogResource $r) => [$r->id => $r->label()]);

        $rows = [];
        foreach ($groups as $key => $stats) {
            $rows[] = [
                'resource' => $key === '' ? null : $key,
                'label' => $key === '' ? 'No asset named' : ($labels[$key] ?? 'Unknown asset'),
                'fields' => array_values(array_map(fn (string $name) => [
                    'name' => $name, 'label' => $numeric[$name]['label'], 'unit' => $numeric[$name]['unit'] ?? null,
                    'count' => $stats[$name]['count'], 'min' => $stats[$name]['min'], 'max' => $stats[$name]['max'],
                    'average' => $stats[$name]['count'] > 0 ? round($stats[$name]['sum'] / $stats[$name]['count'], 2) : null,
                    // Never a total of temperatures, pressures or levels.
                    'sum' => Fields::summable($numeric[$name]) && $stats[$name]['count'] > 0 ? round($stats[$name]['sum'], 2) : null,
                    'outOfRange' => $stats[$name]['out'],
                ], array_keys($numeric))),
            ];
        }
        usort($rows, fn ($a, $b) => strcmp($a['label'], $b['label']));

        return $rows;
    }

    /**
     * Expected readings by sheet and asset over the period.
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: list<array<string, mixed>>, totals: array<string, int|float|null>}
     */
    public static function compliance(array $filters): array
    {
        $assetIds = isset($filters['category']) || isset($filters['kind']) || isset($filters['location']) || isset($filters['resource'])
            ? self::assetQuery($filters)->pluck('id')->all() : null;
        $now = CarbonImmutable::now();
        $rows = [];
        $occurrences = self::occurrences($filters)->when($assetIds !== null, fn (Builder $q) => $q->whereIn('resource_id', $assetIds))
            ->with(['sheet:id,code,name', 'resource:id,code,name'])->get();
        foreach ($occurrences as $o) {
            /** @var ScheduleOccurrence $o */
            $key = $o->log_sheet_id.'|'.($o->resource_id ?? '');
            $rows[$key] ??= [
                'sheetId' => $o->log_sheet_id, 'sheet' => $o->sheet->code.' · '.$o->sheet->name,
                'resourceId' => $o->resource_id, 'resource' => $o->resource?->label(),
                'expected' => 0, 'onTime' => 0, 'late' => 0, 'missed' => 0, 'excused' => 0, 'awaitingReview' => 0, 'open' => 0, 'upcoming' => 0,
            ];
            $r = &$rows[$key];
            if ($o->due_at->gt($now) && $o->log_entry_id === null && $o->status === 'open') {
                $r['upcoming']++;

                continue;
            }
            match (true) {
                $o->status === 'excused' => $r['excused']++,
                $o->status === 'missed' => $r['missed']++,
                default => null,
            };
            if ($o->status !== 'excused') {
                $r['expected']++;
            }
            // Submission timeliness, whatever the review.
            if ($o->timeliness === 'on_time') {
                $r['onTime']++;
            } elseif ($o->timeliness === 'late') {
                $r['late']++;
            }
            if ($o->status === 'open' && $o->log_entry_id !== null) {
                $r['awaitingReview']++;
            } elseif ($o->status === 'open') {
                $r['open']++;
            }
            unset($r);
        }
        $rows = array_values($rows);
        foreach ($rows as &$row) {
            $row['rate'] = $row['expected'] > 0 ? round(100 * ($row['onTime'] + $row['late']) / $row['expected'], 1) : null;
        }
        unset($row);
        usort($rows, fn ($a, $b) => [$a['sheet'], (string) $a['resource']] <=> [$b['sheet'], (string) $b['resource']]);
        $totals = [];
        foreach (['expected', 'onTime', 'late', 'missed', 'excused', 'awaitingReview', 'open', 'upcoming'] as $k) {
            $totals[$k] = array_sum(array_column($rows, $k));
        }
        $totals['rate'] = $totals['expected'] > 0 ? round(100 * ($totals['onTime'] + $totals['late']) / $totals['expected'], 1) : null;

        return ['rows' => $rows, 'totals' => $totals];
    }

    /**
     * Expected readings the member may see, in the period (by due time),
     * for the logbook, sheet and responsible person filtered.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<ScheduleOccurrence>
     */
    public static function occurrences(array $filters): Builder
    {
        $tz = Workspace::current()->timezone ?? 'UTC';

        return Schedules::visible()
            ->when($filters['logbook'] ?? null, fn (Builder $q, $id) => $q->where('logbook_id', $id))
            ->when($filters['sheet'] ?? null, fn (Builder $q, $id) => $q->where('log_sheet_id', $id))
            ->when($filters['person'] ?? null, fn (Builder $q, $id) => $q->where('responsible_id', $id))
            ->when($filters['resource'] ?? null, fn (Builder $q, $id) => $q->where('resource_id', $id))
            ->when($filters['from'] ?? null, fn (Builder $q, $d) => $q->where('due_at', '>=', CarbonImmutable::parse($d, $tz)->startOfDay()->utc()))
            ->when($filters['to'] ?? null, fn (Builder $q, $d) => $q->where('due_at', '<', CarbonImmutable::parse($d, $tz)->addDay()->startOfDay()->utc()));
    }

    /**
     * A location and every place inside it.
     *
     * @return list<string>
     */
    public static function placesIn(string $locationId): array
    {
        $all = Location::query()->get(['id', 'parent_id']);
        $places = [$locationId];
        for ($i = 0; $i < count($places) && $i < 500; $i++) {
            foreach ($all->where('parent_id', $places[$i]) as $child) {
                $places[] = (string) $child->id;
            }
        }

        return $places;
    }
}
