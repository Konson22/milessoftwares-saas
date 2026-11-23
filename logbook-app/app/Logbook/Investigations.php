<?php

namespace App\Logbook;

use App\Logbook\Models\Entry;
use App\Logbook\Models\Investigation;
use App\Logbook\Models\InvestigationEvidence;
use App\Logbook\Models\InvestigationNote;
use App\Logbook\Models\LogException;
use App\Logbook\Models\LogResource;
use App\Logbook\Models\Task;
use App\Logbook\Models\Track;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Investigations (ADR-0029): link evidence, record findings, move through
 * a small lifecycle. Nothing here changes an entry, exception, task or
 * route; evidence is a pointer read with the viewer's own access.
 *
 * Who:
 * - managing (create, link, write, move, close): `logbook.investigations.manage`
 *   and reach of the investigation's logbook;
 * - reading: that, or `logbook.audit.view` with reach of the logbook.
 * Opening an investigation never opens its evidence: each item is shown
 * only if the viewer could open that record anyway; otherwise it is listed
 * as restricted, with its kind and when it was added, and nothing else.
 *
 * Lifecycle: open → investigating → findings_recorded → closed; back from
 * findings_recorded to investigating; closed → investigating only by
 * reopening with a reason. Closing needs a closure decision and at least
 * one finding written by a person. No cause or blame is ever worked out.
 */
final class Investigations
{
    public const MANAGE = 'logbook.investigations.manage';

    /** @var array<string, list<string>> */
    private const MOVES = [
        'open' => ['investigating'],
        'investigating' => ['findings_recorded'],
        'findings_recorded' => ['investigating'],
    ];

    public static function canManage(?Member $member = null): bool
    {
        return ($member ?? Member::current())->can(self::MANAGE);
    }

    public static function canView(?Member $member = null): bool
    {
        $member ??= Member::current();

        return $member->can(self::MANAGE) || $member->can('logbook.audit.view');
    }

    /** @return Builder<Investigation> */
    public static function visible(?Member $member = null): Builder
    {
        $member ??= Member::current();
        if (! self::canView($member)) {
            return Investigation::query()->whereRaw('1 = 0');
        }

        return Investigation::query()->whereIn('logbook_id', Access::logbooks($member)->select('id'));
    }

    public static function find(string $id): Investigation
    {
        return self::visible()->findOrFail($id);
    }

    public static function managed(string $id): Investigation
    {
        abort_unless(self::canManage(), 403);

        return self::find($id);
    }

    /**
     * Open investigations the viewer may add records to: for pickers.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function openOptions(): array
    {
        return array_values(self::visible()->whereIn('status', Investigation::OPEN)->latest()->limit(100)->get(['id', 'reference', 'title'])
            ->map(fn (Investigation $i) => ['value' => $i->id, 'label' => "{$i->reference} · {$i->title}"])->all());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function create(array $data, User $by): Investigation
    {
        self::mustReachLogbook((string) $data['logbook_id']);
        self::checkResource($data['resource_id'] ?? null);

        return DB::transaction(function () use ($data, $by) {
            $investigation = Investigation::query()->create([
                ...self::fields($data),
                'reference' => References::next(References::INVESTIGATIONS['sequence'], References::INVESTIGATIONS['prefix']),
                'logbook_id' => $data['logbook_id'],
                'investigator_id' => $data['investigator_id'] ?? $by->id,
                'status' => 'open',
                'created_by' => $by->id,
            ]);
            AuditTrail::record('investigation.opened', $investigation, $by, ['investigator' => Tasks::name($investigation->investigator_id)]);

            return $investigation;
        });
    }

    /**
     * Title, description, item, incident time, period, investigator.
     *
     * @param  array<string, mixed>  $data
     */
    public static function update(Investigation $investigation, array $data, User $by, ?int $revision): Investigation
    {
        self::mustBeOpen($investigation);
        self::checkResource($data['resource_id'] ?? null);

        return self::locked($investigation, $revision, function (Investigation $i) use ($data, $by) {
            $before = $i->only(['title', 'investigator_id', 'occurred_at', 'period_from', 'period_to', 'resource_id']);
            $i->fill(self::fields($data));
            if (isset($data['investigator_id'])) {
                $i->investigator_id = (string) $data['investigator_id'];
            }
            $changed = array_keys($i->getDirty());
            $i->revision++;
            $i->save();
            AuditTrail::record('investigation.updated', $i, $by, [
                'changed' => $changed === [] ? null : implode(', ', $changed),
                'investigator' => $before['investigator_id'] !== $i->investigator_id ? Tasks::name($i->investigator_id) : null,
            ]);

            return $i;
        });
    }

    public static function move(Investigation $investigation, string $to, User $by, ?int $revision): Investigation
    {
        if (! in_array($to, self::MOVES[$investigation->status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => 'This investigation cannot move to that state from '.self::label($investigation->status).'.']);
        }
        if ($to === 'findings_recorded' && ! self::hasFinding($investigation)) {
            throw ValidationException::withMessages(['status' => 'Write at least one finding before marking findings recorded.']);
        }

        return self::locked($investigation, $revision, function (Investigation $i) use ($to, $by) {
            $from = $i->status;
            $i->status = $to;
            $i->revision++;
            $i->save();
            AuditTrail::record('investigation.moved', $i, $by, ['from' => $from, 'to' => $to]);

            return $i;
        });
    }

    public static function close(Investigation $investigation, string $decision, User $by, ?int $revision): Investigation
    {
        self::mustBeOpen($investigation);
        if (! self::hasFinding($investigation)) {
            throw ValidationException::withMessages(['closure_decision' => 'Write at least one finding before closing.']);
        }

        return self::locked($investigation, $revision, function (Investigation $i) use ($decision, $by) {
            $from = $i->status;
            $i->forceFill(['status' => 'closed', 'closure_decision' => $decision, 'closed_at' => now(), 'closed_by' => $by->id]);
            $i->revision++;
            $i->save();
            AuditTrail::record('investigation.closed', $i, $by, ['from' => $from, 'decision' => mb_strimwidth($decision, 0, 500, '…')]);

            return $i;
        });
    }

    /** The closure decision stays in the audit trail; the investigation goes back to investigating. */
    public static function reopen(Investigation $investigation, string $reason, User $by, ?int $revision): Investigation
    {
        if ($investigation->status !== 'closed') {
            throw ValidationException::withMessages(['reason' => 'Only a closed investigation can be reopened.']);
        }

        return self::locked($investigation, $revision, function (Investigation $i) use ($reason, $by) {
            $i->forceFill(['status' => 'investigating', 'reopened_at' => now(), 'closed_at' => null, 'closed_by' => null]);
            $i->revision++;
            $i->save();
            AuditTrail::record('investigation.reopened', $i, $by, ['reason' => $reason]);

            return $i;
        });
    }

    /**
     * Links a record the person can open themselves. An entry is linked at
     * a submitted revision (the current one unless an earlier one is named).
     */
    public static function link(Investigation $investigation, string $kind, string $subjectId, User $by, ?string $note = null, ?int $revision = null, string $role = 'evidence'): InvestigationEvidence
    {
        self::mustBeOpen($investigation);
        [$label, $logbookId, $entryRevision] = match ($kind) {
            'entry' => self::entrySubject($subjectId, $revision),
            'exception' => self::subject(Exceptions::visible()->find($subjectId), fn (LogException $x) => [$x->reference, $x->logbook_id]),
            'task' => self::subject(Tasks::visible()->find($subjectId), fn (Task $t) => [$t->reference, $t->logbook_id]),
            'track' => self::subject(Tracks::visible()->with('user:id,name', 'sheet:id,code')->find($subjectId), fn (Track $t) => ["Route · {$t->sheet->code} · {$t->user->name} · ".$t->started_at->format('Y-m-d H:i'), $t->logbook_id]),
            default => throw ValidationException::withMessages(['kind' => 'Choose an entry, exception, task or route.']),
        };

        $already = InvestigationEvidence::query()->where('investigation_id', $investigation->id)->where('kind', $kind)
            ->where('subject_id', $subjectId)->whereNull('removed_at')
            ->when($kind === 'entry', fn (Builder $q) => $q->where('entry_revision', $entryRevision))->exists();
        if ($already) {
            throw ValidationException::withMessages(['subject_id' => "{$label} is already linked".($kind === 'entry' ? " at revision {$entryRevision}" : '').'.']);
        }

        return DB::transaction(function () use ($investigation, $kind, $subjectId, $label, $logbookId, $entryRevision, $note, $role, $by) {
            $evidence = InvestigationEvidence::query()->create([
                'investigation_id' => $investigation->id, 'kind' => $kind, 'subject_id' => $subjectId,
                'entry_revision' => $entryRevision, 'logbook_id' => $logbookId, 'label' => mb_substr($label, 0, 200),
                'role' => $role, 'note' => $note, 'added_by' => $by->id, 'added_at' => now(),
            ]);
            AuditTrail::record('investigation.linked', $investigation, $by, [
                // The evidence row, not the record's reference: the history is read by people who may not open it.
                'kind' => $kind, 'evidence' => $evidence->id, 'revision' => $entryRevision, 'role' => $role === 'corrective' ? 'corrective action' : null,
            ]);

            return $evidence;
        });
    }

    public static function unlink(Investigation $investigation, InvestigationEvidence $evidence, string $reason, User $by): void
    {
        self::mustBeOpen($investigation);
        abort_unless($evidence->investigation_id === $investigation->id && $evidence->removed_at === null, 404);

        DB::transaction(function () use ($investigation, $evidence, $reason, $by) {
            $evidence->forceFill(['removed_at' => now(), 'removed_by' => $by->id, 'removal_reason' => $reason])->save();
            AuditTrail::record('investigation.unlinked', $investigation, $by, ['kind' => $evidence->kind, 'evidence' => $evidence->id]);
        });
    }

    public static function note(Investigation $investigation, string $kind, string $body, User $by): InvestigationNote
    {
        self::mustBeOpen($investigation);

        return DB::transaction(function () use ($investigation, $kind, $body, $by) {
            $note = InvestigationNote::query()->create([
                'investigation_id' => $investigation->id, 'kind' => $kind, 'body' => $body,
                'version' => 1, 'author_id' => $by->id, 'created_at' => now(),
            ]);
            AuditTrail::record('investigation.noted', $investigation, $by, ['kind' => $kind]);

            return $note;
        });
    }

    /** A new wording: a new row that supersedes the current one, which is kept. */
    public static function revise(Investigation $investigation, InvestigationNote $note, string $body, User $by): InvestigationNote
    {
        self::mustBeOpen($investigation);
        abort_unless($note->investigation_id === $investigation->id, 404);
        if ($note->superseded_at !== null) {
            throw ValidationException::withMessages(['body' => 'This wording has already been replaced; edit the current one.']);
        }

        return DB::transaction(function () use ($investigation, $note, $body, $by) {
            $note->forceFill(['superseded_at' => now()])->save();
            $next = InvestigationNote::query()->create([
                'investigation_id' => $investigation->id, 'kind' => $note->kind, 'body' => $body,
                'version' => $note->version + 1, 'supersedes_id' => $note->id, 'author_id' => $by->id, 'created_at' => now(),
            ]);
            AuditTrail::record('investigation.note_revised', $investigation, $by, ['kind' => $note->kind, 'version' => $next->version]);

            return $next;
        });
    }

    /**
     * Each linked record, as the viewer may see it: the reference and a
     * link when they could open it; otherwise "restricted" with only its
     * kind and when it was added.
     *
     * @param  Collection<int, InvestigationEvidence>  $items
     * @return list<array<string, mixed>>
     */
    public static function present(Collection $items, ?Member $member = null): array
    {
        $member ??= Member::current();
        $readable = self::readable($items, $member);
        $tz = Workspace::current()->timezone ?? 'UTC';

        return array_values($items->map(function (InvestigationEvidence $e) use ($readable, $tz) {
            $open = in_array("{$e->kind}:{$e->subject_id}", $readable, true);
            $removed = $e->removed_at !== null;

            return [
                'id' => $e->id,
                'kind' => $e->kind,
                'role' => $e->role,
                'restricted' => ! $open,
                'label' => $open ? $e->label : null,
                'subjectId' => $open ? $e->subject_id : null,
                'revision' => $open ? $e->entry_revision : null,
                'note' => $open ? $e->note : null,
                'addedBy' => $e->adder->name,
                'addedAt' => $e->added_at->setTimezone($tz)->format(DATE_ATOM),
                'removed' => $removed ? [
                    'at' => $e->removed_at->setTimezone($tz)->format(DATE_ATOM), 'by' => $e->remover?->name, 'reason' => $open ? $e->removal_reason : null,
                ] : null,
            ];
        })->all());
    }

    /**
     * "kind:id" of the linked records the member could open.
     *
     * @param  Collection<int, InvestigationEvidence>  $items
     * @return list<string>
     */
    public static function readable(Collection $items, Member $member): array
    {
        $ids = fn (string $kind) => $items->where('kind', $kind)->pluck('subject_id')->unique()->values()->all();
        $keys = [];
        $add = function (string $kind, array $found) use (&$keys) {
            foreach ($found as $id) {
                $keys[] = "{$kind}:{$id}";
            }
        };
        if (($e = $ids('entry')) !== []) {
            $add('entry', Access::entries($member)->where('status', '!=', 'draft')->whereIn('id', $e)->pluck('id')->all());
        }
        if (($x = $ids('exception')) !== []) {
            $add('exception', Exceptions::visible($member)->whereIn('id', $x)->pluck('id')->all());
        }
        if (($t = $ids('task')) !== []) {
            $add('task', Tasks::visible($member)->whereIn('id', $t)->pluck('id')->all());
        }
        if (($r = $ids('track')) !== []) {
            $add('track', Tracks::visible($member)->whereIn('id', $r)->pluck('id')->all());
        }

        return $keys;
    }

    public static function label(string $status): string
    {
        return match ($status) {
            'open' => 'Open',
            'investigating' => 'Investigating',
            'findings_recorded' => 'Findings recorded',
            'closed' => 'Closed',
            default => $status,
        };
    }

    private static function hasFinding(Investigation $investigation): bool
    {
        return InvestigationNote::query()->where('investigation_id', $investigation->id)->where('kind', 'finding')->whereNull('superseded_at')->exists();
    }

    /**
     * @return array{0: string, 1: string, 2: int}
     */
    private static function entrySubject(string $id, ?int $revision): array
    {
        $entry = Access::entries()->where('status', '!=', 'draft')->with('sheet:id,name')->find($id);
        if (! $entry instanceof Entry) {
            throw ValidationException::withMessages(['subject_id' => 'Choose a submitted entry you can open.']);
        }
        $revision ??= $entry->revision;
        if ($revision < 1 || $revision > $entry->revision) {
            throw ValidationException::withMessages(['revision' => "This entry has revisions 1 to {$entry->revision}."]);
        }

        return [($entry->reference ?? 'Entry').' · '.$entry->sheet->name, $entry->logbook_id, $revision];
    }

    /**
     * @template T of object
     *
     * @param  T|null  $record
     * @param  callable(T): array{0: string, 1: string|null}  $describe
     * @return array{0: string, 1: string|null, 2: null}
     */
    private static function subject(?object $record, callable $describe): array
    {
        if ($record === null) {
            throw ValidationException::withMessages(['subject_id' => 'Choose a record you can open.']);
        }
        [$label, $logbookId] = $describe($record);

        return [$label, $logbookId, null];
    }

    private static function mustReachLogbook(string $logbookId): void
    {
        if (! Access::reachesLogbook($logbookId)) {
            throw ValidationException::withMessages(['logbook_id' => 'Choose a logbook you can open.']);
        }
    }

    private static function checkResource(mixed $resourceId): void
    {
        if (is_string($resourceId) && $resourceId !== '' && ! LogResource::query()->whereKey($resourceId)->exists()) {
            throw ValidationException::withMessages(['resource_id' => 'Choose a resource of this workspace.']);
        }
    }

    private static function mustBeOpen(Investigation $investigation): void
    {
        if (! $investigation->isOpen()) {
            throw ValidationException::withMessages(['status' => 'This investigation is closed. Reopen it, with a reason, to change it.']);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function fields(array $data): array
    {
        return array_intersect_key([
            'title' => $data['title'] ?? null,
            'description' => $data['description'] ?? null,
            'resource_id' => ($data['resource_id'] ?? '') === '' ? null : $data['resource_id'],
            'occurred_at' => ($data['occurred_at'] ?? '') === '' ? null : $data['occurred_at'],
            'period_from' => ($data['period_from'] ?? '') === '' ? null : $data['period_from'],
            'period_to' => ($data['period_to'] ?? '') === '' ? null : $data['period_to'],
        ], array_flip(array_keys($data)));
    }

    /**
     * Runs the change on the locked row, refusing it when someone else
     * changed the investigation since the person loaded it.
     *
     * @param  callable(Investigation): Investigation  $change
     */
    private static function locked(Investigation $investigation, ?int $revision, callable $change): Investigation
    {
        return DB::transaction(function () use ($investigation, $revision, $change) {
            $fresh = Investigation::query()->whereKey($investigation->id)->lockForUpdate()->firstOrFail();
            if ($revision !== null && $fresh->revision !== $revision) {
                throw new StaleRecord($fresh->revision, 'Someone changed this investigation since you opened it. Reload to see their changes.');
            }

            return $change($fresh);
        });
    }
}
