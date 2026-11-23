<?php

namespace App\Logbook;

use App\Logbook\Models\Entry;
use App\Logbook\Models\EntryReview;
use App\Logbook\Models\EntryRevision;
use App\Logbook\Models\LogException;
use App\Logbook\Models\Task;
use App\Logbook\Models\Track;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * One chronological list for the report's filters (ADR-0029): entries
 * (when the activity happened, and when it was submitted), later
 * revisions, review decisions, exceptions raised and settled, tasks given
 * and completed, and routes recorded.
 *
 * Every event says which clock it is on: `occurred` (when the activity
 * happened, as the person recorded it) or `recorded` (when the system
 * received or decided something). The two are never mixed silently.
 *
 * Built from what the viewer may read: entries from Access::entries(),
 * exceptions and tasks as their own pages show them (visible()), routes from
 * Tracks::visible(). Read only.
 */
final class Timeline
{
    /** The most events shown; the newest are kept. */
    public const LIMIT = 300;

    /** Entries read to build the list. */
    private const ENTRY_LIMIT = 500;

    /**
     * @param  array<string, mixed>  $filters
     * @return array{events: list<array<string, mixed>>, truncated: bool}
     */
    public static function build(array $filters): array
    {
        $tz = Workspace::current()->timezone ?? 'UTC';
        $from = isset($filters['from']) ? CarbonImmutable::parse((string) $filters['from'], $tz)->startOfDay()->utc() : null;
        $to = isset($filters['to']) ? CarbonImmutable::parse((string) $filters['to'], $tz)->addDay()->startOfDay()->utc() : null;
        $events = [];

        $entries = Reports::query($filters)->with(['sheet:id,name,code', 'creator:id,name'])
            ->reorder()->latest('occurred_at')->limit(self::ENTRY_LIMIT)->get();
        $entryIds = $entries->pluck('id')->all();
        $truncated = $entries->count() >= self::ENTRY_LIMIT;

        foreach ($entries as $e) {
            /** @var Entry $e */
            $label = ($e->reference ?? 'Entry').' · '.$e->sheet->name;
            $events[] = self::event('entry.occurred', $e->occurred_at, 'occurred', $label, "Activity recorded by {$e->creator->name}", 'entry', $e->id, $e->creator->name);
            if ($e->submitted_at !== null) {
                $events[] = self::event('entry.submitted', $e->submitted_at, 'recorded', $label, 'Submitted'.($e->revision > 1 ? " (revision {$e->revision} now current)" : ''), 'entry', $e->id, $e->creator->name);
            }
        }

        if ($entryIds !== []) {
            // Later submissions: the first is the entry's own submission above.
            foreach (EntryRevision::query()->whereIn('log_entry_id', $entryIds)->where('revision', '>', 1)->with(['entry:id,reference,log_sheet_id', 'entry.sheet:id,name', 'submitter:id,name'])->get() as $r) {
                /** @var EntryRevision $r */
                $events[] = self::event('entry.revised', $r->submitted_at, 'recorded', ($r->entry->reference ?? 'Entry').' · '.$r->entry->sheet->name, "Revision {$r->revision} submitted", 'entry', $r->log_entry_id, $r->submitter->name);
            }
            foreach (EntryReview::query()->whereIn('log_entry_id', $entryIds)->with('entry:id,reference')->get() as $r) {
                /** @var EntryReview $r */
                $events[] = self::event('review.'.$r->decision, $r->decided_at, 'recorded', ($r->entry->reference ?? 'Entry').' · review', ucfirst($r->decision)." (revision {$r->revision})".($r->comment ? ': '.mb_strimwidth($r->comment, 0, 120, '…') : ''), 'entry', $r->log_entry_id, $r->reviewer_name);
            }
        }

        $scoped = function (Builder $q, string $table) use ($filters, $entryIds) {
            $q->when($filters['logbook'] ?? null, fn (Builder $w, $id) => $w->where("{$table}.logbook_id", $id))
                ->when($filters['sheet'] ?? null, fn (Builder $w, $id) => $w->where("{$table}.log_sheet_id", $id));
            // An item, a place or a person narrows to what touches it, or to the entries found.
            $resources = isset($filters['resource']) ? [(string) $filters['resource']] : (isset($filters['location']) ? Reports::resourcesAt((string) $filters['location']) : null);
            if ($resources !== null || isset($filters['person'])) {
                $q->where(function (Builder $w) use ($table, $resources, $filters, $entryIds) {
                    $w->whereIn("{$table}.log_entry_id", $entryIds === [] ? ['00000000-0000-0000-0000-000000000000'] : $entryIds);
                    if ($resources !== null) {
                        $w->orWhereIn("{$table}.resource_id", $resources === [] ? ['00000000-0000-0000-0000-000000000000'] : $resources);
                    }
                    if (isset($filters['person'])) {
                        $w->orWhere("{$table}.assignee_id", $filters['person']);
                    }
                });
            }
        };

        $exceptions = Exceptions::visible()->with(['raiser:id,name', 'resolver:id,name', 'closer:id,name']);
        $scoped($exceptions, 'log_exceptions');
        $exceptions->when($from, fn (Builder $q, $d) => $q->where(fn (Builder $w) => $w->where('created_at', '>=', $d)->orWhere('resolved_at', '>=', $d)->orWhere('closed_at', '>=', $d)))
            ->when($to, fn (Builder $q, $d) => $q->where('created_at', '<', $d));
        foreach ($exceptions->latest()->limit(self::LIMIT)->get() as $x) {
            /** @var LogException $x */
            $label = "{$x->reference} · {$x->title}";
            $events[] = self::event('exception.raised', $x->created_at, 'recorded', $label, ucfirst($x->severity).' exception raised'.($x->field_label ? " on {$x->field_label}" : ''), 'exception', $x->id, $x->raiser?->name, $x->log_entry_id, $x->severity);
            if ($x->resolved_at !== null) {
                $events[] = self::event('exception.resolved', $x->resolved_at, 'recorded', $label, 'Resolved', 'exception', $x->id, $x->resolver?->name, $x->log_entry_id);
            }
            if ($x->closed_at !== null) {
                $events[] = self::event('exception.closed', $x->closed_at, 'recorded', $label, 'Closed', 'exception', $x->id, $x->closer?->name, $x->log_entry_id);
            }
        }

        $tasks = Tasks::visible()->with(['assignee:id,name', 'creator:id,name']);
        $scoped($tasks, 'log_tasks');
        $tasks->when($from, fn (Builder $q, $d) => $q->where(fn (Builder $w) => $w->where('created_at', '>=', $d)->orWhere('completed_at', '>=', $d)))
            ->when($to, fn (Builder $q, $d) => $q->where('created_at', '<', $d));
        foreach ($tasks->latest()->limit(self::LIMIT)->get() as $t) {
            /** @var Task $t */
            $label = "{$t->reference} · {$t->title}";
            $events[] = self::event('task.created', $t->created_at, 'recorded', $label, "Given to {$t->assignee->name}".($t->due_at ? ', due '.$t->due_at->setTimezone($tz)->format('j M H:i') : ''), 'task', $t->id, $t->creator->name, $t->log_entry_id);
            if ($t->completed_at !== null) {
                $events[] = self::event('task.completed', $t->completed_at, 'recorded', $label, 'Completed', 'task', $t->id, $t->assignee->name, $t->log_entry_id);
            }
        }

        $tracks = Tracks::visible()->with(['user:id,name', 'sheet:id,code,name'])
            ->when($filters['logbook'] ?? null, fn (Builder $q, $id) => $q->where('logbook_id', $id))
            ->when($filters['sheet'] ?? null, fn (Builder $q, $id) => $q->where('log_sheet_id', $id))
            ->when($filters['resource'] ?? null, fn (Builder $q, $id) => $q->where('log_resource_id', $id))
            ->when($filters['person'] ?? null, fn (Builder $q, $id) => $q->where('user_id', $id))
            ->when($from, fn (Builder $q, $d) => $q->where('started_at', '>=', $d))
            ->when($to, fn (Builder $q, $d) => $q->where('started_at', '<', $d));
        foreach ($tracks->latest('started_at')->limit(100)->get() as $r) {
            /** @var Track $r */
            $km = number_format($r->distance_m / 1000, 1);
            $events[] = self::event('route.started', $r->started_at, 'occurred', "Route · {$r->sheet->code}", 'Route started on the phone', 'track', $r->id, $r->user->name);
            if ($r->ended_at !== null) {
                $events[] = self::event('route.ended', $r->ended_at, 'occurred', "Route · {$r->sheet->code}", "Route ended · about {$km} km", 'track', $r->id, $r->user->name);
            }
        }

        usort($events, fn (array $a, array $b) => [$b['at'], $b['kind']] <=> [$a['at'], $a['kind']]);

        return [
            'events' => array_slice($events, 0, self::LIMIT),
            'truncated' => $truncated || count($events) > self::LIMIT,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function event(string $kind, ?CarbonInterface $at, string $basis, string $title, string $detail, string $type, string $id, ?string $person, ?string $entryId = null, ?string $severity = null): array
    {
        return [
            'key' => "{$kind}:{$id}:".($at?->getTimestamp() ?? 0),
            'kind' => $kind,
            'at' => $at?->format(DATE_ATOM),
            'basis' => $basis,
            'title' => $title,
            'detail' => $detail,
            'subject' => ['type' => $type, 'id' => $id],
            'entryId' => $type === 'entry' ? $id : $entryId,
            'person' => $person,
            'severity' => $severity,
        ];
    }
}
