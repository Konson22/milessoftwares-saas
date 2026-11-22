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
use App\Logbook\Models\TaskSeries;
use App\Logbook\Models\WorkFile;
use App\Logbook\Reminders;
use App\Logbook\Tasks;
use App\Logbook\WorkFiles;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Tasks on the web (ADR-0026 §2.4): my tasks, tasks I gave, all tasks
 * (managers), recurring tasks; a task's page with its work, verification
 * and history. Every action is decided by App\Logbook\Tasks.
 */
class TaskController extends Controller
{
    use PresentsWork;

    public const TABS = ['mine', 'given', 'all', 'recurring'];

    public const FILTERS = ['open', 'pending', 'in_progress', 'awaiting_verification', 'returned', 'overdue', 'completed', 'cancelled', 'any'];

    public function index(Request $request): Response
    {
        $member = Member::current();
        $manages = $member->can(Tasks::MANAGE);
        $data = $request->validate([
            'tab' => ['nullable', Rule::in(self::TABS)],
            'status' => ['nullable', Rule::in(self::FILTERS)],
            'q' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', Rule::in(['due', 'created', 'priority'])],
        ]);
        $tab = $data['tab'] ?? 'mine';
        if (in_array($tab, ['all', 'recurring'], true) && ! $manages) {
            $tab = 'mine';
        }
        $filter = $data['status'] ?? 'open';
        $now = CarbonImmutable::now();
        $me = $member->id;

        $base = fn () => Tasks::visible($member)->when($tab === 'mine', fn (Builder $q) => $q->where('assignee_id', $me))
            ->when($tab === 'given', fn (Builder $q) => $q->where('created_by', $me));

        $query = $base()
            ->when($filter === 'open', fn (Builder $q) => $q->whereIn('status', [...Task::OPEN, 'awaiting_verification']))
            ->when($filter === 'overdue', fn (Builder $q) => $q->whereIn('status', Task::OPEN)->where('due_at', '<', $now))
            ->when(in_array($filter, Task::STATUSES, true), fn (Builder $q) => $q->where('status', $filter))
            ->when(isset($data['q']) && trim($data['q']) !== '', function (Builder $q) use ($data) {
                $term = '%'.mb_strtolower(trim($data['q'])).'%';
                $q->where(fn (Builder $w) => $w->whereRaw('lower(reference) like ?', [$term])->orWhereRaw('lower(title) like ?', [$term]));
            })
            ->with(['assignee:id,name', 'creator:id,name', 'logbook:id,name', 'sheet:id,name,code', 'resource:id,code,name']);

        match ($data['sort'] ?? 'due') {
            'created' => $query->latest('created_at'),
            'priority' => $query->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 when 'normal' then 2 else 3 end")->orderBy('due_at'),
            default => $query->orderByRaw('case when due_at is null then 1 else 0 end')->orderBy('due_at')->orderBy('created_at'),
        };

        $counts = [];
        foreach (['pending', 'in_progress', 'awaiting_verification', 'returned', 'completed', 'cancelled'] as $status) {
            $counts[$status] = $base()->where('status', $status)->count();
        }
        $counts['overdue'] = $base()->whereIn('status', Task::OPEN)->where('due_at', '<', $now)->count();

        return Inertia::render('tasks/index', [
            'tab' => $tab,
            'filters' => ['status' => $filter, 'q' => $data['q'] ?? '', 'sort' => $data['sort'] ?? 'due'],
            'counts' => $counts,
            'charts' => $tab === 'recurring' ? null : self::charts($base, $now, $tab),
            'tasks' => $tab === 'recurring' ? null : $query->paginate(25)->withQueryString()->through(fn (Task $t) => self::taskRow($t, $now)),
            'series' => $tab === 'recurring'
                ? TaskSeries::query()->with(['assignee:id,name', 'logbook:id,name'])->where(fn (Builder $q) => $q->whereNull('logbook_id')->orWhereIn('logbook_id', Access::logbooks()->select('id')))
                    ->orderByDesc('active')->orderBy('title')->get()->map(fn (TaskSeries $s) => self::seriesRow($s))->values()
                : null,
            'canManage' => $manages,
            'options' => $manages ? self::options() : null,
        ]);
    }

    /**
     * The charts above the list, over the tab's tasks: created and
     * completed per day for two weeks, open work due over the next week,
     * open work per person (not on "My tasks") and by priority.
     *
     * @param  Closure(): Builder<Task>  $base
     * @return array<string, mixed>
     */
    private static function charts(Closure $base, CarbonImmutable $now, string $tab): array
    {
        $tz = Workspace::current()?->timezone ?? 'UTC';
        $today = $now->setTimezone($tz)->startOfDay();
        $from = $today->subDays(13);
        $local = fn ($at) => CarbonImmutable::parse($at)->setTimezone($tz)->toDateString();

        $created = $base()->where('created_at', '>=', $from->utc())->pluck('created_at')->countBy($local);
        $completed = $base()->whereNotNull('completed_at')->where('completed_at', '>=', $from->utc())
            ->whereIn('status', ['completed', 'awaiting_verification'])->pluck('completed_at')->countBy($local);
        $flow = [];
        for ($d = $from; $d->lte($today); $d = $d->addDay()) {
            $key = $d->toDateString();
            $flow[] = ['date' => $key, 'created' => (int) ($created[$key] ?? 0), 'completed' => (int) ($completed[$key] ?? 0)];
        }

        $open = $base()->whereIn('status', Task::OPEN)->with('assignee:id,name')->get(['id', 'assignee_id', 'priority', 'due_at']);
        $overdue = fn (Task $t) => $t->due_at !== null && $t->due_at->lt($now);

        $dueByDay = $open->filter(fn (Task $t) => $t->due_at !== null && ! $overdue($t))->countBy(fn (Task $t) => $local($t->due_at));
        $due = [['date' => null, 'label' => 'Overdue', 'count' => $open->filter($overdue)->count()]];
        for ($d = $today; $d->lt($today->addDays(7)); $d = $d->addDay()) {
            $due[] = ['date' => $d->toDateString(), 'label' => null, 'count' => (int) ($dueByDay[$d->toDateString()] ?? 0)];
        }

        $workload = $tab === 'mine' ? [] : $open->groupBy('assignee_id')
            ->map(fn ($tasks) => [
                'name' => $tasks->first()->assignee->name,
                'open' => $tasks->reject($overdue)->count(),
                'overdue' => $tasks->filter($overdue)->count(),
            ])
            ->sortByDesc(fn ($row) => $row['open'] + $row['overdue'])->take(8)->values()->all();

        return [
            'flow' => $flow,
            'due' => $due,
            'workload' => $workload,
            'priority' => collect(['urgent', 'high', 'normal', 'low'])->mapWithKeys(fn ($p) => [$p => $open->where('priority', $p)->count()]),
        ];
    }

    public function show(string $task): Response
    {
        $task = Tasks::find($task);
        $member = Member::current();

        return Inertia::render('tasks/show', [
            'task' => self::taskDetail($task, $member),
            'history' => self::history('task', $task->id),
            'options' => Tasks::manages($task, $member) ? self::options() : null,
            'myEntries' => $task->assignee_id === $member->id
                ? Access::entries()->where('created_by', $member->id)->where('status', '!=', 'draft')->latest('submitted_at')->limit(30)->get(['id', 'reference', 'log_sheet_id'])
                    ->map(fn ($e) => ['value' => $e->id, 'label' => (string) $e->reference])->values()
                : [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $task = Tasks::create($this->input($request), $this->user());

        return to_route('tasks.show', $task->id)->with('success', "{$task->reference} given to ".$task->assignee->name.'.');
    }

    public function update(Request $request, string $task): RedirectResponse
    {
        $task = $this->managed($task);
        $task = Tasks::update($task, $this->input($request), $this->user(), $this->revision($request));

        return back()->with('success', "{$task->reference} saved.");
    }

    public function assign(Request $request, string $task): RedirectResponse
    {
        $task = $this->managed($task);
        $data = $request->validate(['assignee_id' => ['required', 'uuid']]);
        $task = Tasks::assign($task, $data['assignee_id'], $this->user(), $this->revision($request));

        return back()->with('success', "{$task->reference} given to ".$task->assignee->name.'.');
    }

    public function start(Request $request, string $task): RedirectResponse
    {
        $task = Tasks::start(Tasks::find($task), $this->user(), $this->revision($request));

        return back()->with('success', "{$task->reference} in progress.");
    }

    public function complete(Request $request, string $task): RedirectResponse
    {
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:5000'],
            'completion_entry_id' => ['nullable', 'uuid'],
            'completed_at' => ['nullable', 'date'],
        ]);
        $completedAt = isset($data['completed_at']) ? CarbonImmutable::parse($data['completed_at'], Workspace::current()->timezone ?? 'UTC')->toIso8601String() : null;
        $task = Tasks::complete(Tasks::find($task), $this->user(), $data['notes'] ?? null, $data['completion_entry_id'] ?? null, $completedAt, revision: $this->revision($request));

        return back()->with('success', $task->status === 'completed' ? "{$task->reference} completed." : "{$task->reference} sent for verification.");
    }

    public function verify(Request $request, string $task): RedirectResponse
    {
        $task = $this->managed($task);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['verified', 'returned'])],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);
        $task = Tasks::verify($task, $this->user(), $data['decision'], $data['comment'] ?? null, $this->revision($request));

        return back()->with('success', $task->status === 'completed' ? "{$task->reference} verified." : "{$task->reference} returned to ".$task->assignee->name.'.');
    }

    public function cancel(Request $request, string $task): RedirectResponse
    {
        $task = $this->managed($task);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $task = Tasks::cancel($task, $this->user(), $data['reason'], $this->revision($request));

        return back()->with('success', "{$task->reference} cancelled.");
    }

    public function reopen(Request $request, string $task): RedirectResponse
    {
        $task = $this->managed($task);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);
        $task = Tasks::reopen($task, $this->user(), $data['reason'] ?? null, $this->revision($request));

        return back()->with('success', "{$task->reference} reopened.");
    }

    public function upload(Request $request, string $task): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['brief', 'evidence'])],
            'file' => ['required', 'file', 'max:'.Attachments::MAX_KILOBYTES, 'mimes:'.Attachments::MIMES],
        ]);
        WorkFiles::add(Tasks::find($task), $data['kind'], $data['file'], $this->user());

        return back()->with('success', 'File added.');
    }

    public function removeFile(string $file): RedirectResponse
    {
        $file = WorkFile::query()->whereNotNull('task_id')->findOrFail($file);
        Tasks::find((string) $file->task_id);
        WorkFiles::remove($file, $this->user());

        return back()->with('success', 'File removed.');
    }

    public function download(string $file): StreamedResponse
    {
        $file = WorkFile::query()->findOrFail($file);
        if ($file->task_id !== null) {
            Tasks::find($file->task_id);
        } else {
            Exceptions::find((string) $file->exception_id);
        }
        abort_unless(Storage::disk(Attachments::DISK)->exists($file->path), 404);

        return Storage::disk(Attachments::DISK)->download($file->path, $file->name, [
            'Content-Type' => $file->mime ?? 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    // ---- Recurring tasks ----

    public function storeSeries(Request $request): RedirectResponse
    {
        $series = Tasks::saveSeries(null, $this->seriesInput($request), $this->user());

        return to_route('tasks.index', ['tab' => 'recurring'])->with('success', "Recurring task \"{$series->title}\" set up. Its tasks appear a day ahead.");
    }

    public function updateSeries(Request $request, string $series): RedirectResponse
    {
        $series = TaskSeries::query()->where(fn (Builder $q) => $q->whereNull('logbook_id')->orWhereIn('logbook_id', Access::logbooks()->select('id')))->findOrFail($series);
        $series = Tasks::saveSeries($series, $this->seriesInput($request), $this->user());

        return back()->with('success', "Recurring task \"{$series->title}\" saved.");
    }

    public function pauseSeries(string $series): RedirectResponse
    {
        $series = Tasks::pauseSeries($this->findSeries($series), $this->user());

        return back()->with('success', "\"{$series->title}\" paused. No new tasks until you resume it.");
    }

    public function resumeSeries(string $series): RedirectResponse
    {
        $series = Tasks::resumeSeries($this->findSeries($series), $this->user());

        return back()->with('success', "\"{$series->title}\" resumed. Its next task appears a day ahead.");
    }

    public function endSeries(string $series): RedirectResponse
    {
        $series = Tasks::endSeries($this->findSeries($series), $this->user());

        return back()->with('success', "\"{$series->title}\" ended. Its tasks stay in the history.");
    }

    private function findSeries(string $id): TaskSeries
    {
        return TaskSeries::query()->where(fn (Builder $q) => $q->whereNull('logbook_id')->orWhereIn('logbook_id', Access::logbooks()->select('id')))->findOrFail($id);
    }

    // ---- Helpers ----

    /**
     * Choices for the task forms: people, logbooks, sheets, resources,
     * open exceptions, reminder presets.
     *
     * @return array<string, mixed>
     */
    public static function options(): array
    {
        return [
            'members' => WorkspaceMember::options(),
            ...self::filterOptions(),
            'resources' => array_values(LogResource::query()->where('active', true)->orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn (LogResource $r) => ['value' => $r->id, 'label' => $r->label()])->all()),
            'exceptions' => array_values(Exceptions::visible()->whereIn('status', LogException::OPEN)->latest('created_at')->limit(100)->get(['id', 'reference', 'title'])
                ->map(fn (LogException $e) => ['value' => $e->id, 'label' => "{$e->reference} · {$e->title}"])->all()),
            'reminders' => Reminders::PRESETS,
            'timezone' => Workspace::current()->timezone ?? 'UTC',
        ];
    }

    private function managed(string $id): Task
    {
        $task = Tasks::find($id);
        abort_unless(Tasks::manages($task), 403, 'You do not manage this task.');

        return $task;
    }

    private function revision(Request $request): ?int
    {
        $revision = $request->input('revision');

        return is_numeric($revision) ? (int) $revision : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function input(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'priority' => ['nullable', Rule::in(Task::PRIORITIES)],
            'assignee_id' => ['sometimes', 'required', 'uuid'],
            'logbook_id' => ['nullable', 'uuid'],
            'log_sheet_id' => ['nullable', 'uuid'],
            'log_entry_id' => ['nullable', 'uuid'],
            'exception_id' => ['nullable', 'uuid'],
            'resource_id' => ['nullable', 'uuid'],
            'starts_at' => ['nullable', 'date'],
            'due_at' => ['nullable', 'date'],
            'reminders' => ['nullable', 'array', 'max:6'],
            'reminders.*' => ['string', 'max:40'],
            'reminder_channels' => ['nullable', 'array'],
            'reminder_channels.*' => [Rule::in(Reminders::CHANNELS)],
            'verification_required' => ['boolean'],
            'evidence_required' => ['boolean'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function seriesInput(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'priority' => ['nullable', Rule::in(Task::PRIORITIES)],
            'assignee_id' => ['required', 'uuid'],
            'logbook_id' => ['nullable', 'uuid'],
            'log_sheet_id' => ['nullable', 'uuid'],
            'resource_id' => ['nullable', 'uuid'],
            'frequency' => ['required', Rule::in(['daily', 'weekly', 'monthly'])],
            'weekdays' => ['nullable', 'array'],
            'weekdays.*' => ['integer', 'between:1,7'],
            'day_of_month' => ['nullable', 'integer', 'between:1,31'],
            'start_time' => ['required', 'string'],
            'due_time' => ['required', 'string'],
            'timezone' => ['required', 'string'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date'],
            'reminders' => ['nullable', 'array', 'max:6'],
            'reminders.*' => ['string', 'max:40'],
            'reminder_channels' => ['nullable', 'array'],
            'reminder_channels.*' => [Rule::in(Reminders::CHANNELS)],
            'verification_required' => ['boolean'],
            'evidence_required' => ['boolean'],
            'active' => ['boolean'],
        ]);
    }
}
