<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\PresentsWork;
use App\Logbook\Access;
use App\Logbook\Attachments;
use App\Logbook\Exceptions;
use App\Logbook\Member;
use App\Logbook\Models\LogException;
use App\Logbook\Models\LogResource;
use App\Logbook\Models\Task;
use App\Logbook\Models\WorkFile;
use App\Logbook\Tasks;
use App\Logbook\WorkFiles;
use App\Models\WorkspaceMember;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Exceptions on the web (ADR-0026 §2.7): the list, one exception with its
 * source reading, corrective tasks, evidence and history.
 */
class ExceptionController extends Controller
{
    use PresentsWork;

    public function index(Request $request): Response
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'in_progress', 'resolved', 'closed', 'overdue', 'active', 'any'])],
            'severity' => ['nullable', Rule::in(LogException::SEVERITIES)],
            'mine' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $filter = $data['status'] ?? 'active';
        $now = CarbonImmutable::now();
        $me = $this->user()->id;
        $base = fn () => Exceptions::visible()->when($data['mine'] ?? false, fn (Builder $q) => $q->where('assignee_id', $me));

        $query = $base()
            ->when($filter === 'active', fn (Builder $q) => $q->whereIn('status', LogException::OPEN))
            ->when($filter === 'overdue', fn (Builder $q) => $q->whereIn('status', LogException::OPEN)->where('due_at', '<', $now))
            ->when(in_array($filter, LogException::STATUSES, true), fn (Builder $q) => $q->where('status', $filter))
            ->when(isset($data['severity']), fn (Builder $q) => $q->where('severity', $data['severity']))
            ->when(isset($data['q']) && trim($data['q']) !== '', function (Builder $q) use ($data) {
                $term = '%'.mb_strtolower(trim($data['q'])).'%';
                $q->where(fn (Builder $w) => $w->whereRaw('lower(reference) like ?', [$term])->orWhereRaw('lower(title) like ?', [$term]));
            })
            ->with(['logbook:id,name', 'sheet:id,name,code', 'assignee:id,name'])
            ->orderByRaw("case severity when 'critical' then 0 when 'high' then 1 when 'medium' then 2 else 3 end")
            ->latest('created_at');

        $counts = [];
        foreach (LogException::STATUSES as $status) {
            $counts[$status] = $base()->where('status', $status)->count();
        }
        $counts['overdue'] = $base()->whereIn('status', LogException::OPEN)->where('due_at', '<', $now)->count();
        $openBySeverity = $base()->whereIn('status', LogException::OPEN)
            ->selectRaw('severity, count(*) as total')->groupBy('severity')->pluck('total', 'severity');

        return Inertia::render('exceptions/index', [
            'filters' => ['status' => $filter, 'severity' => $data['severity'] ?? '', 'mine' => (bool) ($data['mine'] ?? false), 'q' => $data['q'] ?? ''],
            'counts' => $counts,
            'openBySeverity' => collect(LogException::SEVERITIES)->mapWithKeys(fn ($s) => [$s => (int) ($openBySeverity[$s] ?? 0)]),
            'exceptions' => $query->paginate(25)->withQueryString()->through(fn (LogException $e) => self::exceptionRow($e, $now)),
            'canReport' => Gate::any(['logbook.entries.create', 'logbook.entries.review', Tasks::MANAGE]),
            'options' => [
                'logbooks' => self::filterOptions()['logbooks'],
                'resources' => array_values(LogResource::query()->where('active', true)->orderBy('code')->get(['id', 'code', 'name'])
                    ->map(fn (LogResource $r) => ['value' => $r->id, 'label' => $r->label()])->all()),
            ],
        ]);
    }

    public function show(string $exception): Response
    {
        $exception = Exceptions::find($exception);
        $exception->load(['logbook:id,name', 'sheet:id,name,code', 'assignee:id,name', 'raiser:id,name', 'resource', 'files']);
        $member = Member::current();
        $manages = Exceptions::manages($exception, $member);
        $entry = $exception->log_entry_id !== null ? Access::entries()->with('sheet:id,name,code')->find($exception->log_entry_id) : null;
        $mine = $exception->assignee_id === $member->id;

        return Inertia::render('exceptions/show', [
            'exception' => [
                ...self::exceptionRow($exception),
                'description' => $exception->description,
                'field' => $exception->field_label,
                'recordedValue' => $exception->recorded_value,
                'expected' => $exception->expected,
                'resource' => $exception->resource?->label(),
                'raisedBy' => $exception->raised_by !== null ? Tasks::name($exception->raised_by) : 'Logbook',
                'resolutionNotes' => $exception->resolution_notes,
                'resolvedBy' => $exception->resolved_by !== null ? Tasks::name($exception->resolved_by) : null,
                'closedAt' => self::moment($exception->closed_at),
                'closedBy' => $exception->closed_by !== null ? Tasks::name($exception->closed_by) : null,
                'entry' => $entry !== null ? ['id' => $entry->id, 'reference' => $entry->reference, 'sheet' => $entry->sheet->name] : null,
                'files' => $exception->files->map(fn (WorkFile $f) => WorkFiles::row($f))->values()->all(),
            ],
            'tasks' => Tasks::visible()->where('exception_id', $exception->id)->with(['assignee:id,name', 'creator:id,name', 'logbook:id,name', 'sheet:id,name,code', 'resource:id,code,name'])
                ->orderBy('created_at')->get()->map(fn (Task $t) => self::taskRow($t))->values(),
            'history' => self::history('exception', $exception->id),
            'can' => [
                'manage' => $manages,
                'assign' => $manages && $exception->isOpen(),
                'work' => ($mine || $manages) && $exception->isOpen(),
                'start' => ($mine || $manages) && $exception->status === 'open' && $exception->assignee_id !== null,
                'close' => $manages && $exception->status === 'resolved' && $exception->resolved_by !== $member->id,
                'reopen' => $manages && ! $exception->isOpen(),
                'task' => $manages && $exception->isOpen(),
            ],
            'members' => $manages ? WorkspaceMember::options() : [],
            'taskOptions' => $manages ? TaskController::options() : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(Gate::any(['logbook.entries.create', 'logbook.entries.review', Tasks::MANAGE]), 403);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'severity' => ['required', Rule::in(LogException::SEVERITIES)],
            'log_entry_id' => ['nullable', 'uuid'],
            'logbook_id' => ['nullable', 'uuid'],
            'resource_id' => ['nullable', 'uuid'],
            'field' => ['nullable', 'string', 'max:64'],
            'assignee_id' => ['nullable', 'uuid'],
            'due_at' => ['nullable', 'date'],
        ]);
        $exception = Exceptions::report($data, $this->user());

        return to_route('exceptions.show', $exception->id)->with('success', "{$exception->reference} reported.");
    }

    public function assign(Request $request, string $exception): RedirectResponse
    {
        $exception = $this->managed($exception);
        $data = $request->validate(['assignee_id' => ['required', 'uuid'], 'due_at' => ['nullable', 'date']]);
        $exception = Exceptions::assign($exception, $data['assignee_id'], $data['due_at'] ?? null, $this->user(), $this->revision($request));

        return back()->with('success', "{$exception->reference} assigned.");
    }

    public function start(Request $request, string $exception): RedirectResponse
    {
        $exception = Exceptions::start(Exceptions::find($exception), $this->user(), $this->revision($request));

        return back()->with('success', "{$exception->reference} in progress.");
    }

    public function resolve(Request $request, string $exception): RedirectResponse
    {
        $data = $request->validate(['resolution_notes' => ['required', 'string', 'max:5000']]);
        $exception = Exceptions::resolve(Exceptions::find($exception), $this->user(), $data['resolution_notes'], $this->revision($request));

        return back()->with('success', "{$exception->reference} resolved.");
    }

    public function close(Request $request, string $exception): RedirectResponse
    {
        $exception = Exceptions::close($this->managed($exception), $this->user(), $this->revision($request));

        return back()->with('success', "{$exception->reference} closed.");
    }

    public function reopen(Request $request, string $exception): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $exception = Exceptions::reopen($this->managed($exception), $this->user(), $data['reason'], $this->revision($request));

        return back()->with('success', "{$exception->reference} reopened.");
    }

    public function upload(Request $request, string $exception): RedirectResponse
    {
        $data = $request->validate(['file' => ['required', 'file', 'max:'.Attachments::MAX_KILOBYTES, 'mimes:'.Attachments::MIMES]]);
        WorkFiles::add(Exceptions::find($exception), 'evidence', $data['file'], $this->user());

        return back()->with('success', 'File added.');
    }

    public function removeFile(string $file): RedirectResponse
    {
        $file = WorkFile::query()->whereNotNull('exception_id')->findOrFail($file);
        Exceptions::find((string) $file->exception_id);
        WorkFiles::remove($file, $this->user());

        return back()->with('success', 'File removed.');
    }

    private function managed(string $id): LogException
    {
        $exception = Exceptions::find($id);
        abort_unless(Exceptions::manages($exception), 403, 'You do not follow up this exception.');

        return $exception;
    }

    private function revision(Request $request): ?int
    {
        $revision = $request->input('revision');

        return is_numeric($revision) ? (int) $revision : null;
    }
}
