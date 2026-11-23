<?php

namespace App\Http\Controllers;

use App\Logbook\Access;
use App\Logbook\AuditTrail;
use App\Logbook\Exceptions;
use App\Logbook\Investigations;
use App\Logbook\Member;
use App\Logbook\Models\AuditEvent;
use App\Logbook\Models\EntryRevision;
use App\Logbook\Models\Investigation;
use App\Logbook\Models\InvestigationEvidence;
use App\Logbook\Models\InvestigationNote;
use App\Logbook\Models\LogException;
use App\Logbook\Models\Task;
use App\Logbook\Models\Track;
use App\Logbook\Tasks;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Investigations (ADR-0029): list, open, link evidence, write notes and
 * findings, move, close, reopen, and print. Every change goes through
 * App\Logbook\Investigations; evidence is shown with the viewer's access.
 */
class InvestigationController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless(Investigations::canView(), 403);
        $data = $request->validate(['status' => ['nullable', Rule::in([...Investigation::STATUSES, 'active', 'any'])], 'q' => ['nullable', 'string', 'max:100']]);
        $status = $data['status'] ?? 'active';

        $rows = Investigations::visible()->with(['logbook:id,name', 'investigator:id,name', 'resource:id,code,name'])
            ->withCount(['evidence as evidence_count' => fn ($q) => $q->whereNull('removed_at')])
            ->when($status === 'active', fn ($q) => $q->whereIn('status', Investigation::OPEN))
            ->when(in_array($status, Investigation::STATUSES, true), fn ($q) => $q->where('status', $status))
            ->when(trim((string) ($data['q'] ?? '')) !== '', function ($q) use ($data) {
                $term = '%'.addcslashes(mb_strtolower(trim((string) $data['q'])), '%_\\').'%';
                $q->where(fn ($w) => $w->whereRaw('lower(title) like ?', [$term])->orWhere('reference', 'like', mb_strtoupper($term)));
            })
            ->latest()->paginate(25)->withQueryString()
            ->through(fn (Investigation $i) => self::row($i));

        return Inertia::render('investigations/index', [
            'investigations' => $rows,
            'filters' => ['status' => $status, 'q' => $data['q'] ?? null],
            'counts' => collect(Investigation::STATUSES)->mapWithKeys(fn ($s) => [$s => Investigations::visible()->where('status', $s)->count()]),
            'canManage' => Investigations::canManage(),
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless(Investigations::canManage(), 403);
        $data = $request->validate(['entry' => ['nullable', 'uuid'], 'resource' => ['nullable', 'uuid'], 'logbook' => ['nullable', 'uuid']]);
        $entry = isset($data['entry']) ? Access::entries()->where('status', '!=', 'draft')->with('sheet:id,name')->find((string) $data['entry']) : null;

        return Inertia::render('investigations/create', [
            'logbooks' => array_values(Access::logbooks()->where('status', 'active')->orderBy('name')->get(['id', 'name'])->map(fn ($l) => ['value' => $l->id, 'label' => $l->name])->all()),
            'resources' => self::resourceOptions(),
            'members' => WorkspaceMember::options(),
            'initial' => [
                'logbook_id' => $entry !== null ? $entry->logbook_id : ($data['logbook'] ?? ''),
                'resource_id' => $data['resource'] ?? '',
                'entry' => $entry ? ['id' => $entry->id, 'label' => ($entry->reference ?? 'Entry').' · '.$entry->sheet->name, 'occurredAt' => self::time($entry->occurred_at)] : null,
            ],
            'me' => $this->user()->id,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(Investigations::canManage(), 403);
        $data = $request->validate([...self::rules(), 'logbook_id' => ['required', 'uuid'], 'entry_id' => ['nullable', 'uuid']]);
        $investigation = Investigations::create($data, $this->user());
        if (isset($data['entry_id'])) {
            Investigations::link($investigation, 'entry', $data['entry_id'], $this->user());
        }

        return to_route('investigations.show', $investigation->id)->with('success', "{$investigation->reference} opened.");
    }

    public function show(string $investigation): Response
    {
        $investigation = Investigations::find($investigation);
        $investigation->load(['logbook:id,name', 'investigator:id,name', 'creator:id,name', 'closer:id,name', 'resource:id,code,name']);
        $evidence = $investigation->evidence()->with(['adder:id,name', 'remover:id,name'])->get();
        $notes = $investigation->notes()->with('author:id,name')->get();
        $manage = Investigations::canManage() && $investigation->isOpen();

        return Inertia::render('investigations/show', [
            'investigation' => self::row($investigation) + [
                'description' => $investigation->description,
                'creator' => $investigation->creator->name,
                'closureDecision' => $investigation->closure_decision,
                'closedAt' => self::time($investigation->closed_at),
                'closedBy' => $investigation->closer?->name,
                'reopenedAt' => self::time($investigation->reopened_at),
                'revision' => $investigation->revision,
                'logbookId' => $investigation->logbook_id,
                'resourceId' => $investigation->resource_id,
                'investigatorId' => $investigation->investigator_id,
                'periodFrom' => $investigation->period_from?->toDateString(),
                'periodTo' => $investigation->period_to?->toDateString(),
            ],
            'evidence' => Investigations::present($evidence),
            'timeline' => self::evidenceTimeline($evidence),
            'notes' => $notes->map(fn (InvestigationNote $n) => [
                'id' => $n->id, 'kind' => $n->kind, 'body' => $n->body, 'version' => $n->version, 'supersedesId' => $n->supersedes_id,
                'current' => $n->superseded_at === null, 'author' => $n->author->name, 'at' => self::time($n->created_at),
            ])->values(),
            'history' => AuditEvent::query()->where('subject_type', 'investigation')->where('subject_id', $investigation->id)->orderBy('created_at')->get()
                ->map(fn (AuditEvent $e) => ['id' => $e->id, 'action' => $e->action, 'user' => $e->user_name, 'details' => $e->details, 'at' => self::time($e->created_at)]),
            'resources' => $manage ? self::resourceOptions() : [],
            'members' => $manage || (Investigations::canManage() && $investigation->status === 'closed') ? WorkspaceMember::options() : [],
            'can' => [
                'manage' => $manage,
                'reopen' => Investigations::canManage() && $investigation->status === 'closed',
                'createTask' => $manage && Gate::allows(Tasks::MANAGE),
            ],
        ]);
    }

    public function update(Request $request, string $investigation): RedirectResponse
    {
        $investigation = Investigations::managed($investigation);
        $data = $request->validate([...self::rules(), 'revision' => ['nullable', 'integer']]);
        Investigations::update($investigation, $data, $this->user(), $this->revision($request));

        return back()->with('success', 'Investigation saved.');
    }

    public function move(Request $request, string $investigation): RedirectResponse
    {
        $investigation = Investigations::managed($investigation);
        $data = $request->validate(['status' => ['required', Rule::in(['investigating', 'findings_recorded'])]]);
        $investigation = Investigations::move($investigation, $data['status'], $this->user(), $this->revision($request));

        return back()->with('success', $investigation->reference.' is now '.mb_strtolower(Investigations::label($investigation->status)).'.');
    }

    public function close(Request $request, string $investigation): RedirectResponse
    {
        $investigation = Investigations::managed($investigation);
        $data = $request->validate(['closure_decision' => ['required', 'string', 'min:10', 'max:5000']]);
        Investigations::close($investigation, $data['closure_decision'], $this->user(), $this->revision($request));

        return back()->with('success', "{$investigation->reference} closed.");
    }

    public function reopen(Request $request, string $investigation): RedirectResponse
    {
        $investigation = Investigations::managed($investigation);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);
        Investigations::reopen($investigation, $data['reason'], $this->user(), $this->revision($request));

        return back()->with('success', "{$investigation->reference} reopened.");
    }

    public function link(Request $request, string $investigation): RedirectResponse
    {
        $investigation = Investigations::managed($investigation);
        $data = $request->validate([
            'kind' => ['required', Rule::in(InvestigationEvidence::KINDS)],
            'subject_id' => ['nullable', 'uuid', 'required_without:reference'],
            // A reference typed by hand: GEN-2026-000004, EXC-…, TASK-….
            'reference' => ['nullable', 'string', 'max:32'],
            'revision' => ['nullable', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:2000'],
            'role' => ['nullable', Rule::in(InvestigationEvidence::ROLES)],
        ]);
        $subject = $data['subject_id'] ?? self::byReference($data['kind'], (string) $data['reference']);
        $evidence = Investigations::link($investigation, $data['kind'], $subject, $this->user(), $data['note'] ?? null, $data['revision'] ?? null, $data['role'] ?? 'evidence');

        return back()->with('success', "{$evidence->label} linked to {$investigation->reference}.");
    }

    public function unlink(Request $request, string $investigation, string $evidence): RedirectResponse
    {
        $investigation = Investigations::managed($investigation);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $item = InvestigationEvidence::query()->where('investigation_id', $investigation->id)->findOrFail($evidence);
        Investigations::unlink($investigation, $item, $data['reason'], $this->user());

        return back()->with('success', 'Link removed; it stays listed as removed.');
    }

    public function note(Request $request, string $investigation): RedirectResponse
    {
        $investigation = Investigations::managed($investigation);
        $data = $request->validate(['kind' => ['required', Rule::in(InvestigationNote::KINDS)], 'body' => ['required', 'string', 'max:10000']]);
        Investigations::note($investigation, $data['kind'], $data['body'], $this->user());

        return back()->with('success', 'Note added.');
    }

    public function revise(Request $request, string $investigation, string $note): RedirectResponse
    {
        $investigation = Investigations::managed($investigation);
        $data = $request->validate(['body' => ['required', 'string', 'max:10000']]);
        $current = InvestigationNote::query()->where('investigation_id', $investigation->id)->findOrFail($note);
        Investigations::revise($investigation, $current, $data['body'], $this->user());

        return back()->with('success', 'Note updated; the earlier wording is kept.');
    }

    /**
     * Gives a corrective task through the ordinary task rules and links it
     * to the investigation as a corrective action.
     */
    public function task(Request $request, string $investigation): RedirectResponse
    {
        $investigation = Investigations::managed($investigation);
        abort_unless(Gate::allows(Tasks::MANAGE), 403);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'assignee_id' => ['required', 'uuid'],
            'due_at' => ['nullable', 'date'],
            'priority' => ['nullable', Rule::in(Task::PRIORITIES)],
        ]);
        $task = Tasks::create([...$data, 'logbook_id' => $investigation->logbook_id, 'resource_id' => $investigation->resource_id], $this->user());
        Investigations::link($investigation, 'task', $task->id, $this->user(), null, null, 'corrective');

        return back()->with('success', "{$task->reference} given to {$task->assignee->name} and linked as a corrective action.");
    }

    /** The investigation as a PDF: what a person wrote, and references to the records. Audited. */
    public function pdf(string $investigation): HttpResponse
    {
        $investigation = Investigations::find($investigation);
        $investigation->load(['logbook:id,name', 'investigator:id,name', 'creator:id,name', 'closer:id,name', 'resource:id,code,name']);
        $evidence = $investigation->evidence()->with(['adder:id,name', 'remover:id,name'])->get();
        $tz = Workspace::current()->timezone ?? 'UTC';
        AuditTrail::record('investigation.printed', $investigation, $this->user());

        return Pdf::loadView('logbook.investigation-pdf', [
            'organization' => Workspace::current()?->display_name,
            'investigation' => $investigation,
            'status' => Investigations::label($investigation->status),
            'evidence' => Investigations::present($evidence),
            'timeline' => self::evidenceTimeline($evidence),
            'notes' => $investigation->notes()->with('author:id,name')->whereNull('superseded_at')->get(),
            'timezone' => $tz,
            'printedBy' => $this->user()->name,
            'printedAt' => now($tz),
        ])->setPaper('a4')->download(strtolower($investigation->reference).'.pdf');
    }

    /**
     * When each readable linked record happened, in order: the entry's
     * activity (at the linked revision), the exception raised, the task
     * given and completed, the route recorded. Restricted items add nothing.
     *
     * @param  Collection<int, InvestigationEvidence>  $items
     * @return list<array<string, mixed>>
     */
    private static function evidenceTimeline($items): array
    {
        $member = Member::current();
        $active = $items->whereNull('removed_at');
        $readable = Investigations::readable($active, $member);
        $ok = fn (InvestigationEvidence $e) => in_array("{$e->kind}:{$e->subject_id}", $readable, true);
        $events = [];
        $push = function (?\DateTimeInterface $at, string $basis, string $title, string $detail, string $type, string $id) use (&$events) {
            if ($at !== null) {
                $events[] = ['at' => $at->format(DATE_ATOM), 'basis' => $basis, 'title' => $title, 'detail' => $detail, 'subject' => ['type' => $type, 'id' => $id]];
            }
        };

        $entries = $active->where('kind', 'entry')->filter($ok);
        if ($entries->isNotEmpty()) {
            $revisions = EntryRevision::query()->whereIn('log_entry_id', $entries->pluck('subject_id'))->get()->groupBy('log_entry_id');
            foreach ($entries as $e) {
                $rev = $revisions->get($e->subject_id)?->firstWhere('revision', $e->entry_revision);
                $push($rev?->occurred_at, 'occurred', $e->label, "Activity (revision {$e->entry_revision})", 'entry', $e->subject_id);
                $push($rev?->submitted_at, 'recorded', $e->label, "Revision {$e->entry_revision} submitted", 'entry', $e->subject_id);
            }
        }
        foreach (LogException::query()->whereIn('id', $active->where('kind', 'exception')->filter($ok)->pluck('subject_id'))->get() as $x) {
            $push($x->created_at, 'recorded', "{$x->reference} · {$x->title}", ucfirst($x->severity).' exception raised', 'exception', $x->id);
            $push($x->resolved_at, 'recorded', "{$x->reference} · {$x->title}", 'Resolved', 'exception', $x->id);
        }
        foreach (Task::query()->whereIn('id', $active->where('kind', 'task')->filter($ok)->pluck('subject_id'))->with('assignee:id,name')->get() as $t) {
            $push($t->created_at, 'recorded', "{$t->reference} · {$t->title}", "Given to {$t->assignee->name}", 'task', $t->id);
            $push($t->completed_at, 'recorded', "{$t->reference} · {$t->title}", 'Completed', 'task', $t->id);
        }
        foreach (Track::query()->whereIn('id', $active->where('kind', 'track')->filter($ok)->pluck('subject_id'))->get() as $r) {
            $push($r->started_at, 'occurred', 'Route', 'Route started', 'track', $r->id);
            $push($r->ended_at, 'occurred', 'Route', 'Route ended', 'track', $r->id);
        }
        usort($events, fn ($a, $b) => $a['at'] <=> $b['at']);

        return $events;
    }

    private static function byReference(string $kind, string $reference): string
    {
        $reference = mb_strtoupper(trim($reference));
        $id = match ($kind) {
            'entry' => Access::entries()->where('status', '!=', 'draft')->where('reference', $reference)->value('id'),
            'exception' => Exceptions::visible()->where('reference', $reference)->value('id'),
            'task' => Tasks::visible()->where('reference', $reference)->value('id'),
            default => null,
        };
        if (! is_string($id)) {
            throw ValidationException::withMessages(['reference' => "No {$kind} {$reference} that you can open."]);
        }

        return $id;
    }

    /** @return array<string, mixed> */
    private static function row(Investigation $i): array
    {
        return [
            'id' => $i->id,
            'reference' => $i->reference,
            'title' => $i->title,
            'status' => $i->status,
            'statusLabel' => Investigations::label($i->status),
            'logbook' => $i->logbook->name,
            'investigator' => $i->investigator->name,
            'resource' => $i->resource?->label(),
            'occurredAt' => self::time($i->occurred_at),
            'period' => [$i->period_from?->toDateString(), $i->period_to?->toDateString()],
            'evidenceCount' => $i->evidence_count ?? null,
            'createdAt' => self::time($i->created_at),
        ];
    }

    /** @return array<string, mixed> */
    private static function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:10000'],
            'resource_id' => ['nullable', 'uuid'],
            'occurred_at' => ['nullable', 'date'],
            'period_from' => ['nullable', 'date_format:Y-m-d'],
            'period_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:period_from'],
            'investigator_id' => ['nullable', 'uuid', Rule::in(WorkspaceMember::query()->pluck('user_id')->all())],
        ];
    }

    private function revision(Request $request): ?int
    {
        $revision = $request->input('revision');

        return is_numeric($revision) ? (int) $revision : null;
    }
}
