<?php

namespace App\Logbook;

use App\Logbook\Models\Entry;
use App\Logbook\Models\LogSheet;
use App\Logbook\Models\LogSheetVersion;
use App\Models\Workspace;
use Carbon\CarbonImmutable;

/**
 * The number fields of one log sheet, per day (ADR-0029): the total,
 * average, lowest and highest of each, across the filtered entries.
 *
 * A field is identified by its name, which keeps its kind across versions
 * (Sheets::keepsNamesTyped); each entry is read with its own version. A
 * field whose unit changed between versions is reported per unit, never
 * added across units. Days are the workspace's days, by when the activity
 * happened.
 */
final class Trends
{
    /**
     * @param  array<string, mixed>  $filters
     * @return list<array{name: string, label: string, unit: string|null, days: list<array{day: string, count: int, sum: float, average: float, min: float, max: float}>}>
     */
    public static function forSheet(LogSheet $sheet, array $filters): array
    {
        // name => [unit => label], newest version first, so the label is the current one.
        $fields = [];
        $unitsByVersion = [];
        foreach (LogSheetVersion::query()->where('log_sheet_id', $sheet->id)->where('status', 'published')->orderByDesc('version')->get() as $version) {
            foreach ($version->fields as $field) {
                if (! in_array($field['type'], Fields::NUMERIC, true)) {
                    continue;
                }
                $unit = isset($field['unit']) && $field['unit'] !== '' ? (string) $field['unit'] : null;
                $fields[$field['name']][$unit ?? ''] ??= (string) $field['label'];
                $unitsByVersion[$version->id][$field['name']] = $unit ?? '';
            }
        }
        if ($fields === []) {
            return [];
        }

        $tz = Workspace::current()->timezone ?? 'UTC';
        $query = Reports::query([...$filters, 'sheet' => $sheet->id])->reorder()->select(['id', 'values', 'occurred_at', 'log_sheet_version_id'])
            ->limit(Reports::SUMMARY_LIMIT);
        /** @var array<string, array<string, array{count: int, sum: float, min: float, max: float}>> $stats */
        $stats = [];
        foreach ($query->cursor() as $entry) {
            /** @var Entry $entry */
            $day = CarbonImmutable::parse($entry->occurred_at)->setTimezone($tz)->toDateString();
            foreach ($unitsByVersion[$entry->log_sheet_version_id] ?? [] as $name => $unit) {
                $v = $entry->values[$name] ?? null;
                if (! is_numeric($v)) {
                    continue;
                }
                $v = (float) $v;
                $key = "{$name}\0{$unit}";
                $s = $stats[$key][$day] ?? ['count' => 0, 'sum' => 0.0, 'min' => $v, 'max' => $v];
                $stats[$key][$day] = ['count' => $s['count'] + 1, 'sum' => $s['sum'] + $v, 'min' => min($s['min'], $v), 'max' => max($s['max'], $v)];
            }
        }

        $series = [];
        foreach ($fields as $name => $units) {
            foreach ($units as $unit => $label) {
                $days = $stats["{$name}\0{$unit}"] ?? [];
                ksort($days);
                $series[] = [
                    'name' => count($units) > 1 ? "{$name}:{$unit}" : $name,
                    'label' => $label,
                    'unit' => $unit === '' ? null : $unit,
                    'days' => array_map(fn (string $day, array $s) => [
                        'day' => $day, 'count' => $s['count'], 'sum' => round($s['sum'], 4),
                        'average' => round($s['sum'] / $s['count'], 4), 'min' => $s['min'], 'max' => $s['max'],
                    ], array_keys($days), array_values($days)),
                ];
            }
        }

        return $series;
    }
}
