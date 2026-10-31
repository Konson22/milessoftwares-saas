<?php

namespace App\Http\Controllers;

use App\Actions\Tasks;
use App\Enums\TaskPriority;
use App\Http\Controllers\Concerns\ShowsProject;
use App\Models\Milestone;
use App\Models\Person;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TimeEntry;
use App\Support\ActivityLog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Tenancy\CurrentTenant;

/**
 * A project's tasks, three ways (board, list, timeline), and each task with
 * its dependencies, comments and time.
 */
class TaskController extends Controller
{
    use ShowsProject;

    public function board(Request $request, Project $project): Response
    {
        $tasks = $this->tasks($project, $request);

        return Inertia::render('projects/board', [
            'project' => $this->projectHeader($request, $project),
            'columns' => collect(Task::STATUSES)->map(fn (string $status) => [
                'status' => $status,
                'label' => Tasks::label($status),
                'tasks' => $tasks->where('status', $status)->sortBy('position')->values()->map(fn (Task $task) => $this->card($task)),
            ]),
            ...$this->choices($project),
            'filters' => $this->filters($request),
        ]);
    }

    public function index(Request $request, Project $project): Response
    {
        $tasks = $this->tasks($project, $request)
            ->sortBy([
                fn (Task $a, Task $b) => ($a->isDone() <=> $b->isDone()),
                fn (Task $a, Task $b) => $b->priority->weight() <=> $a->priority->weight(),
                fn (Task $a, Task $b) => ($a->due_on?->getTimestamp() ?? PHP_INT_MAX) <=> ($b->due_on?->getTimestamp() ?? PHP_INT_MAX),
            ]);

        return Inertia::render('projects/tasks', [
            'project' => $this->projectHeader($request, $project),
            'tasks' => $tasks->values()->map(fn (Task $task) => $this->card($task)),
            ...$this->choices($project),
            'filters' => $this->filters($request),
        ]);
    }

    /**
     * The plan over time: tasks with dates, milestones, and what waits on what.
     */
    public function timeline(Request $request, Project $project): Response
    {
        $tasks = $project->tasks()->with(['assignee:id,name', 'dependencies:id,number'])
            ->where(fn ($query) => $query->whereNotNull('start_on')->orWhereNotNull('due_on'))
            ->orderByRaw('coalesce(start_on, due_on)')->get();

        return Inertia::render('projects/timeline', [
            'project' => $this->projectHeader($request, $project),
            'tasks' => $tasks->map(fn (Task $task) => [
                'id' => $task->id,
                'number' => $task->number,
                'title' => $task->title,
                'status' => $task->status,
                'assignee' => $task->assignee?->name,
                'startOn' => ($task->start_on ?? $task->due_on)?->toDateString(),
                'dueOn' => ($task->due_on ?? $task->start_on)?->toDateString(),
                'overdue' => $task->overdue(),
                'waitsFor' => $task->dependencies->pluck('number'),
            ]),
            'milestones' => $project->milestones()->get()->map(fn (Milestone $milestone) => [
                'id' => $milestone->id,
                'name' => $milestone->name,
                'dueOn' => $milestone->due_on->toDateString(),
                'done' => $milestone->completed_at !== null,
            ]),
            'undated' => $project->tasks()->whereNull('start_on')->whereNull('due_on')->where('status', '!=', Task::DONE)->count(),
        ]);
    }

    public function store(Request $request, Project $project, Tasks $tasks): RedirectResponse
    {
        $task = $tasks->create($project, $this->details($request, $project), $this->member($request));

        return back()->with('success', "{$task->number} added.");
    }

    public function show(Request $request, Project $project, Task $task): Response
    {
        $task->load(['assignee:id,name', 'milestone:id,name', 'creator:id,name', 'dependencies:id,number,title,status', 'dependents:id,number,title,status', 'comments.author:id,name', 'timeEntries.person:id,name']);
        $finance = $this->can($request, 'projects.finance.view');

        return Inertia::render('projects/task', [
            'project' => $this->projectHeader($request, $project),
            'task' => [
                ...$this->card($task),
                'description' => $task->description,
                'assigneeId' => $task->assignee_id,
                'milestoneId' => $task->milestone_id,
                'createdBy' => $task->creator->name,
                'createdAt' => $task->created_at->toIso8601String(),
                'completedAt' => $task->completed_at?->toIso8601String(),
                'dependencies' => $this->related($task->dependencies),
                'dependents' => $this->related($task->dependents),
                'comments' => $task->comments->map(fn (TaskComment $comment) => [
                    'id' => $comment->id,
                    'body' => $comment->body,
                    'author' => $comment->author->name,
                    'at' => $comment->created_at->toIso8601String(),
                ]),
                'time' => $task->timeEntries->sortByDesc('worked_on')->values()->map(fn (TimeEntry $entry) => [
                    'id' => $entry->id,
                    'person' => $entry->person->name,
                    'workedOn' => $entry->worked_on->toDateString(),
                    'minutes' => $entry->minutes,
                    'note' => $entry->note,
                    'costMinor' => $finance ? $entry->cost_minor : null,
                ]),
                'loggedMinutes' => (int) $task->timeEntries->sum('minutes'),
            ],
            // Tasks it could wait for: the project's others not already linked.
            'candidates' => $project->tasks()->whereKeyNot($task->id)
                ->whereNotIn('id', $task->dependencies->pluck('id'))
                ->orderBy('number')->get(['id', 'number', 'title'])
                ->map(fn (Task $other) => ['value' => $other->id, 'label' => "{$other->number} · {$other->title}"]),
            ...$this->choices($project),
        ]);
    }

    public function update(Request $request, Project $project, Task $task): RedirectResponse
    {
        abort_unless($project->isOpen(), 409, "{$project->code} is closed: reopen it to change its tasks.");
        $details = $this->details($request, $project);
        unset($details['status']);
        $task->fill($details)->save();
        ActivityLog::record($project, $this->member($request), "Changed {$task->number}");

        return back()->with('success', "{$task->number} saved.");
    }

    /**
     * From the board: a new column and place, or just a new status.
     */
    public function move(Request $request, Project $project, Task $task, Tasks $tasks): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(Task::STATUSES)],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);

        $tasks->move($task, $validated['status'], isset($validated['position']) ? (int) $validated['position'] : null, $this->member($request));

        return back();
    }

    public function comment(Request $request, Project $project, Task $task): RedirectResponse
    {
        $validated = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        $task->comments()->create(['user_id' => $this->member($request)->id, 'body' => trim($validated['body'])]);
        ActivityLog::record($project, $this->member($request), "Commented on {$task->number}");

        return back()->with('success', 'Comment added.');
    }

    public function addDependency(Request $request, Project $project, Task $task, Tasks $tasks): RedirectResponse
    {
        $validated = $request->validate(['depends_on_id' => ['required', 'uuid']]);
        $dependsOn = Task::query()->whereKey($validated['depends_on_id'])->firstOrFail();

        $tasks->addDependency($task, $dependsOn, $this->member($request));

        return back()->with('success', "{$task->number} waits for {$dependsOn->number}.");
    }

    public function removeDependency(Project $project, Task $task, Task $dependency, Tasks $tasks): RedirectResponse
    {
        $tasks->removeDependency($task, $dependency);

        return back()->with('success', "{$task->number} no longer waits for {$dependency->number}.");
    }

    /**
     * Tasks linked to another (what it waits for, what waits for it).
     *
     * @param  Collection<int, Task>  $tasks
     * @return list<array{id: string, number: string, title: string, status: string}>
     */
    private function related(Collection $tasks): array
    {
        return array_values($tasks->map(fn (Task $other) => ['id' => $other->id, 'number' => $other->number, 'title' => $other->title, 'status' => $other->status])->all());
    }

    /**
     * @return Collection<int, Task>
     */
    private function tasks(Project $project, Request $request): Collection
    {
        $filters = $this->filters($request);

        return $project->tasks()
            ->with(['assignee:id,name', 'milestone:id,name'])
            ->withCount(['dependencies as open_dependencies_count' => fn ($query) => $query->where('status', '!=', Task::DONE)])
            ->withCount('comments')
            ->when($filters['assignee'] === 'none', fn ($query) => $query->whereNull('assignee_id'))
            ->when(! in_array($filters['assignee'], ['', 'none'], true), fn ($query) => $query->where('assignee_id', $filters['assignee']))
            ->when($filters['milestone'] !== '', fn ($query) => $query->where('milestone_id', $filters['milestone']))
            ->when($filters['search'] !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereRaw('lower(title) like ?', ['%'.mb_strtolower($filters['search']).'%'])
                ->orWhereRaw('lower(number) like ?', ['%'.mb_strtolower($filters['search']).'%'])))
            ->get();
    }

    /**
     * @return array{search: string, assignee: string, milestone: string}
     */
    private function filters(Request $request): array
    {
        return [
            'search' => $request->string('search')->trim()->toString(),
            'assignee' => $request->string('assignee')->toString(),
            'milestone' => $request->string('milestone')->toString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function card(Task $task): array
    {
        return [
            'id' => $task->id,
            'number' => $task->number,
            'title' => $task->title,
            'status' => $task->status,
            'priority' => $task->priority->value,
            'assignee' => $task->assignee?->name,
            'milestone' => $task->milestone?->name,
            'startOn' => $task->start_on?->toDateString(),
            'dueOn' => $task->due_on?->toDateString(),
            'overdue' => $task->overdue(),
            'estimateHours' => $task->estimate_hours === null ? null : (float) $task->estimate_hours,
            'blocked' => (int) ($task->getAttribute('open_dependencies_count') ?? 0) > 0,
            'comments' => (int) ($task->getAttribute('comments_count') ?? 0),
        ];
    }

    /**
     * People, milestones and priorities to choose from on the project's forms.
     *
     * @return array<string, mixed>
     */
    private function choices(Project $project): array
    {
        return [
            'people' => Person::options(),
            'milestones' => $project->milestones()->get()->map(fn (Milestone $milestone) => ['value' => $milestone->id, 'label' => $milestone->name]),
            'priorities' => TaskPriority::options(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function details(Request $request, Project $project): array
    {
        $tenant = app(CurrentTenant::class)->id();
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'status' => ['nullable', Rule::in(Task::STATUSES)],
            'priority' => ['required', Rule::enum(TaskPriority::class)],
            'assignee_id' => ['nullable', 'uuid', Rule::exists('people', 'id')->where('organization_id', $tenant)],
            'milestone_id' => ['nullable', 'uuid', Rule::exists('milestones', 'id')->where('project_id', $project->id)],
            'start_on' => ['nullable', 'date'],
            'due_on' => ['nullable', 'date', 'after_or_equal:start_on'],
            'estimate_hours' => ['nullable', 'numeric', 'min:0', 'max:99999'],
        ]);

        return [
            'title' => trim($validated['title']),
            'description' => $this->text($validated['description'] ?? null),
            'status' => $validated['status'] ?? Task::TODO,
            'priority' => $validated['priority'],
            'assignee_id' => $validated['assignee_id'] ?? null,
            'milestone_id' => $validated['milestone_id'] ?? null,
            'start_on' => $validated['start_on'] ?? null,
            'due_on' => $validated['due_on'] ?? null,
            'estimate_hours' => $validated['estimate_hours'] ?? null,
        ];
    }
}
