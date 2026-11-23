<?php

namespace App\Logbook;

use App\Logbook\Models\Logbook;
use App\Logbook\Models\LogException;
use App\Logbook\Models\ScheduleOccurrence;
use App\Logbook\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * What may need investigating, by department, by logbook and by person,
 * within the logbooks the viewer reaches and the report's filters:
 *
 * - entries returned for correction, and still awaiting review;
 * - exceptions raised (abnormal readings, failed checks, reported
 *   problems), how many are still open, and how many of those are high or
 *   critical;
 * - expected entries missed or late (schedules);
 * - tasks overdue now.
 *
 * A person is counted for what is theirs: entries they wrote, exceptions
 * raised on those entries, expected entries they were responsible for and
 * tasks assigned to them. Each row gets a score (serious open exceptions
 * and missed entries weigh more), and rows come worst first.
 */
final class Issues
{
    private const METRICS = ['entries', 'returned', 'awaiting', 'exceptions', 'open', 'serious', 'missed', 'late', 'overdue'];

    /**
     * @param  array<string, mixed>  $filters
     * @return array{departments: list<array<string, mixed>>, logbooks: list<array<string, mixed>>, people: list<array<string, mixed>>}
     */
    public static function report(array $filters): array
    {
        $tz = Workspace::current()->timezone ?? 'UTC';
        $from = isset($filters['from']) ? CarbonImmutable::parse((string) $filters['from'], $tz)->startOfDay()->utc() : null;
        $to = isset($filters['to']) ? CarbonImmutable::parse((string) $filters['to'], $tz)->addDay()->startOfDay()->utc() : null;
        $reach = Access::logbooks()->select('id');

        $byLogbook = [];
        $byPerson = [];
        $add = function (array &$rows, ?string $key, string $metric, int $n): void {
            if ($key === null || $n === 0) {
                return;
            }
            $rows[$key] ??= array_fill_keys(self::METRICS, 0);
            $rows[$key][$metric] += $n;
        };

        // Entries: everything the report counts, then the returned and waiting ones.
        foreach (Reports::query($filters)->toBase()->reorder()
            ->selectRaw("logbook_id, created_by, count(*) as n, sum(case when status = 'returned' then 1 else 0 end) as returned, sum(case when status = 'submitted' then 1 else 0 end) as awaiting")
            ->groupBy('logbook_id', 'created_by')->get() as $r) {
            foreach (['entries' => $r->n, 'returned' => $r->returned, 'awaiting' => $r->awaiting] as $metric => $n) {
                $add($byLogbook, $r->logbook_id, $metric, (int) $n);
                $add($byPerson, $r->created_by, $metric, (int) $n);
            }
        }

        // Exceptions raised in the period; a person answers for those on their entries.
        $exceptions = self::scoped(LogException::query(), $reach, $filters)
            ->when($from, fn (Builder $q, $d) => $q->where('log_exceptions.created_at', '>=', $d))
            ->when($to, fn (Builder $q, $d) => $q->where('log_exceptions.created_at', '<', $d))
            ->leftJoin('log_entries', function ($join) {
                $join->on('log_entries.id', '=', 'log_exceptions.log_entry_id')->on('log_entries.organization_id', '=', 'log_exceptions.organization_id');
            })
            ->toBase()
            ->selectRaw("log_exceptions.logbook_id, log_entries.created_by as person, count(*) as n, sum(case when log_exceptions.status in ('open','in_progress') then 1 else 0 end) as open, sum(case when log_exceptions.status in ('open','in_progress') and log_exceptions.severity in ('high','critical') then 1 else 0 end) as serious")
            ->groupBy('log_exceptions.logbook_id', 'log_entries.created_by')->get();
        foreach ($exceptions as $r) {
            foreach (['exceptions' => $r->n, 'open' => $r->open, 'serious' => $r->serious] as $metric => $n) {
                $add($byLogbook, $r->logbook_id, $metric, (int) $n);
                $add($byPerson, $r->person, $metric, (int) $n);
            }
        }

        // Expected entries that were missed or came in late.
        $occurrences = self::scoped(ScheduleOccurrence::query(), $reach, $filters)
            ->whereIn('status', ['missed', 'late'])
            ->when($filters['from'] ?? null, fn (Builder $q, $d) => $q->where('occurs_on', '>=', $d))
            ->when($filters['to'] ?? null, fn (Builder $q, $d) => $q->where('occurs_on', '<=', $d))
            ->toBase()->selectRaw('logbook_id, responsible_id, status, count(*) as n')
            ->groupBy('logbook_id', 'responsible_id', 'status')->get();
        foreach ($occurrences as $r) {
            $add($byLogbook, $r->logbook_id, $r->status, (int) $r->n);
            $add($byPerson, $r->responsible_id, $r->status, (int) $r->n);
        }

        // Tasks overdue now (the period does not apply: they are late today).
        $tasks = self::scoped(Task::query(), $reach, $filters)
            ->whereIn('status', Task::OPEN)->whereNotNull('due_at')->where('due_at', '<', now())
            ->toBase()->selectRaw('logbook_id, assignee_id, count(*) as n')
            ->groupBy('logbook_id', 'assignee_id')->get();
        foreach ($tasks as $r) {
            $add($byLogbook, $r->logbook_id, 'overdue', (int) $r->n);
            $add($byPerson, $r->assignee_id, 'overdue', (int) $r->n);
        }

        $logbooks = Logbook::query()->whereIn('id', array_keys($byLogbook))->get(['id', 'name', 'department_id'])->keyBy('id');
        $departmentNames = Departments::names();

        $logbookRows = [];
        $departmentRows = [];
        foreach ($byLogbook as $id => $metrics) {
            $logbook = $logbooks->get($id);
            if ($logbook === null) {
                continue;
            }
            $logbookRows[] = self::row($id, $logbook->name, $metrics, [
                'department' => $logbook->department_id === null ? 'Whole workspace' : ($departmentNames[$logbook->department_id] ?? 'Unknown department'),
            ]);
            $key = $logbook->department_id ?? 'workspace';
            $departmentRows[$key] ??= ['name' => $logbook->department_id === null ? 'Whole workspace' : ($departmentNames[$logbook->department_id] ?? 'Unknown department'), 'metrics' => array_fill_keys(self::METRICS, 0), 'logbooks' => 0];
            $departmentRows[$key]['logbooks']++;
            foreach (self::METRICS as $metric) {
                $departmentRows[$key]['metrics'][$metric] += $metrics[$metric];
            }
        }

        $names = User::query()->whereIn('id', array_keys($byPerson))->pluck('name', 'id');
        $peopleRows = [];
        foreach ($byPerson as $id => $metrics) {
            $peopleRows[] = self::row((string) $id, (string) ($names[$id] ?? 'Unknown'), $metrics);
        }

        return [
            'departments' => self::sorted(array_map(
                fn (string $key, array $d) => self::row($key, $d['name'], $d['metrics'], ['logbooks' => $d['logbooks']]),
                array_keys($departmentRows),
                array_values($departmentRows),
            )),
            'logbooks' => self::sorted($logbookRows),
            'people' => self::sorted($peopleRows),
        ];
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<T>  $query
     * @param  Builder<Logbook>  $reach
     * @param  array<string, mixed>  $filters
     * @return Builder<T>
     */
    private static function scoped(Builder $query, Builder $reach, array $filters): Builder
    {
        $table = $query->getModel()->getTable();

        return $query->whereIn("{$table}.logbook_id", $reach)
            ->when($filters['logbook'] ?? null, fn (Builder $q, $id) => $q->where("{$table}.logbook_id", $id))
            ->when($filters['sheet'] ?? null, fn (Builder $q, $id) => $q->where("{$table}.log_sheet_id", $id));
    }

    /**
     * @param  array<string, int>  $metrics
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private static function row(string $id, string $name, array $metrics, array $extra = []): array
    {
        $score = $metrics['returned'] + $metrics['open'] + 2 * $metrics['serious'] + 2 * $metrics['missed'] + $metrics['late'] + $metrics['overdue'];

        return [
            'id' => $id,
            'name' => $name,
            ...$extra,
            ...$metrics,
            'score' => $score,
            // Serious open exceptions or missed entries: look now.
            'level' => $metrics['serious'] > 0 || $metrics['missed'] > 0 ? 'investigate' : ($score > 0 ? 'watch' : 'clear'),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function sorted(array $rows): array
    {
        usort($rows, fn (array $a, array $b) => [$b['score'], $b['entries'], $a['name']] <=> [$a['score'], $a['entries'], $b['name']]);

        return $rows;
    }
}
