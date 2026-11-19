<?php

namespace App\Logbook;

use App\Logbook\Models\Entry;
use App\Logbook\Models\LogSheet;
use App\Logbook\Models\LogSheetVersion;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * Filtering, counts and numeric summaries over the entries a member may
 * read: everything starts from Access::entries(), so a report never shows
 * more than the member could open one by one. Not a BI engine.
 */
final class Reports
{
    public const REVIEW = ['pending', 'reviewed', 'returned', 'not_required'];

    /** Numeric summaries read at most this many entries. */
    public const SUMMARY_LIMIT = 20000;

    /**
     * Validation rules for the filters every list, report and export takes.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'logbook' => ['nullable', 'uuid'],
            'sheet' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'creator' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::in(Entry::STATUSES)],
            'review' => ['nullable', Rule::in(self::REVIEW)],
            'q' => ['nullable', 'string', 'max:100'],
            'mine' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Entry>
     */
    public static function query(array $filters, bool $includeDrafts = false): Builder
    {
        $tz = Workspace::current()->timezone ?? 'UTC';
        $query = Access::entries();

        if (! $includeDrafts) {
            $query->where('status', '!=', 'draft');
        }

        return $query
            ->when($filters['logbook'] ?? null, fn (Builder $q, $id) => $q->where('logbook_id', $id))
            ->when($filters['sheet'] ?? null, fn (Builder $q, $id) => $q->where('log_sheet_id', $id))
            ->when($filters['from'] ?? null, fn (Builder $q, $d) => $q->where('occurred_at', '>=', CarbonImmutable::parse($d, $tz)->startOfDay()->utc()))
            ->when($filters['to'] ?? null, fn (Builder $q, $d) => $q->where('occurred_at', '<', CarbonImmutable::parse($d, $tz)->addDay()->startOfDay()->utc()))
            ->when($filters['creator'] ?? null, fn (Builder $q, $id) => $q->where('created_by', $id))
            ->when(($filters['mine'] ?? false) == true, fn (Builder $q) => $q->where('created_by', auth()->id()))
            ->when($filters['status'] ?? null, fn (Builder $q, $s) => $q->where('status', $s))
            ->when($filters['review'] ?? null, fn (Builder $q, $r) => match ($r) {
                'pending' => $q->where('status', 'submitted'),
                'reviewed' => $q->where('status', 'reviewed'),
                'returned' => $q->where('status', 'returned'),
                default => $q->where('review_required', false)->where('status', 'finalized'),
            })
            ->when(trim((string) ($filters['q'] ?? '')) !== '', function (Builder $q) use ($filters) {
                $term = '%'.addcslashes(mb_strtolower(trim((string) $filters['q'])), '%_\\').'%';
                $q->where(fn (Builder $w) => $w->where('search_text', 'like', $term)->orWhere('reference', 'like', mb_strtoupper($term)));
            });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{total: int, byStatus: array<string, int>, byDay: list<array{day: string, count: int}>, byContributor: list<array{name: string, count: int}>}
     */
    public static function counts(array $filters): array
    {
        $byStatus = array_fill_keys(['submitted', 'returned', 'reviewed', 'finalized'], 0);
        foreach (self::query($filters)->toBase()->reorder()->selectRaw('status, count(*) as n')->groupBy('status')->get() as $row) {
            $byStatus[$row->status] = (int) $row->n;
        }

        $tz = Workspace::current()->timezone ?? 'UTC';
        $byDay = [];
        foreach (self::query($filters)->reorder()->orderBy('occurred_at')->limit(50000)->pluck('occurred_at') as $at) {
            $day = CarbonImmutable::parse($at)->setTimezone($tz)->toDateString();
            $byDay[$day] = ($byDay[$day] ?? 0) + 1;
        }

        $people = self::query($filters)->toBase()->reorder()->selectRaw('created_by, count(*) as n')->groupBy('created_by')->orderByDesc('n')->limit(50)->get();
        $names = User::query()->whereIn('id', $people->pluck('created_by'))->pluck('name', 'id');

        return [
            'total' => array_sum($byStatus),
            'byStatus' => $byStatus,
            'byDay' => array_map(fn ($d, $n) => ['day' => (string) $d, 'count' => $n], array_keys($byDay), array_values($byDay)),
            'byContributor' => array_values($people->map(fn ($r) => ['name' => (string) ($names[$r->created_by] ?? 'Unknown'), 'count' => (int) $r->n])->all()),
        ];
    }

    /**
     * Count, sum, average, minimum and maximum of each number field of a
     * sheet, across the filtered entries. Entries are read through their
     * own version; a field is matched by name (its kind never changes,
     * Sheets::keepsNamesTyped).
     *
     * @param  array<string, mixed>  $filters
     * @return list<array{name: string, label: string, count: int, sum: float, average: float|null, min: float|null, max: float|null}>|null
     */
    public static function numbers(LogSheet $sheet, array $filters): ?array
    {
        $versions = LogSheetVersion::query()->where('log_sheet_id', $sheet->id)->where('status', 'published')->orderBy('version')->get();
        $numeric = [];
        foreach ($versions as $version) {
            foreach ($version->fields as $field) {
                if (in_array($field['type'], Fields::NUMERIC, true)) {
                    $numeric[$field['name']] = $field['label'];
                }
            }
        }
        if ($numeric === []) {
            return null;
        }

        $stats = array_map(fn () => ['count' => 0, 'sum' => 0.0, 'min' => null, 'max' => null], $numeric);
        self::query([...$filters, 'sheet' => $sheet->id])->reorder()->select(['id', 'values'])->limit(self::SUMMARY_LIMIT)
            ->cursor()->each(function (Entry $entry) use (&$stats) {
                foreach ($stats as $name => &$s) {
                    $v = $entry->values[$name] ?? null;
                    if (! is_numeric($v)) {
                        continue;
                    }
                    $v = (float) $v;
                    $s['count']++;
                    $s['sum'] += $v;
                    $s['min'] = $s['min'] === null ? $v : min($s['min'], $v);
                    $s['max'] = $s['max'] === null ? $v : max($s['max'], $v);
                }
            });

        $out = [];
        foreach ($stats as $name => $s) {
            $out[] = [
                'name' => $name,
                'label' => $numeric[$name],
                'count' => $s['count'],
                'sum' => round($s['sum'], 6),
                'average' => $s['count'] > 0 ? round($s['sum'] / $s['count'], 4) : null,
                'min' => $s['min'],
                'max' => $s['max'],
            ];
        }

        return $out;
    }

    /**
     * The fields a sheet has had, latest label first: export columns.
     *
     * @return list<array<string, mixed>>
     */
    public static function columns(LogSheet $sheet): array
    {
        $columns = [];
        foreach (LogSheetVersion::query()->where('log_sheet_id', $sheet->id)->where('status', 'published')->orderByDesc('version')->get() as $version) {
            foreach ($version->fields as $field) {
                $columns[$field['name']] ??= $field;
            }
        }

        return array_values($columns);
    }
}
