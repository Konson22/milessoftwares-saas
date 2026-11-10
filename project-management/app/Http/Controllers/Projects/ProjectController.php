<?php

namespace App\Http\Controllers\Projects;

use App\Actions\Programmes;
use App\Actions\WorkStructure;
use App\Enums\ExpenseStatus;
use App\Http\Controllers\Concerns\ShowsProject;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Milestone;
use App\Models\Person;
use App\Models\Programme;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Risk;
use App\Models\Task;
use App\Support\ActivityLog;
use App\Support\Money;
use App\Support\ProjectMetrics;
use App\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Projects: the list with how each stands, setting one up, and its
 * overview.
 */
class ProjectController extends Controller
{
    use ShowsProject;

    public function index(Request $request): Response
    {
        $search = $request->string('search')->toString();
        $status = $request->string('status')->toString();

        $projects = Project::query()
            ->search($search)
            ->when(in_array($status, Project::STATUSES, true), fn ($query) => $query->where('status', $status))
            ->when($status === '', fn ($query) => $query->whereIn('status', Project::OPEN))
            ->with('manager:id,name')
            ->orderByRaw('due_on is null')
            ->orderBy('due_on')
            ->orderBy('code')
            ->limit(200)
            ->get();
        $metrics = ProjectMetrics::forProjects($projects);
        $finance = $this->can($request, 'projects.finance.view');

        return Inertia::render('portal/projects/index', [
            'projects' => $projects->map(fn (Project $project) => [
                'id' => $project->id,
                'code' => $project->code,
                'name' => $project->name,
                'client' => $project->client,
                'manager' => $project->manager?->name,
                'status' => $project->status,
                'dueOn' => $project->due_on?->toDateString(),
                'progress' => $metrics[$project->id]['progress'],
                'health' => $project->isOpen() ? $metrics[$project->id]['health'] : null,
                'overdueTasks' => $metrics[$project->id]['overdueTasks'],
                'budgetMinor' => $finance ? $project->budget_minor : null,
                'actualMinor' => $finance ? $metrics[$project->id]['actualMinor'] : null,
                'currency' => $project->currency,
            ]),
            'filters' => ['search' => $search, 'status' => $status],
            'canCreate' => $this->can($request, 'projects.manage'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('portal/projects/form', ['project' => null, 'people' => Person::options(), 'programmes' => $this->programmes()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $project = new Project;
        $project->forceFill([
            'currency' => Money::currency(),
            'created_by' => $this->member($request)->id,
        ]);
        $this->save($request, $project);
        ActivityLog::record($project, $this->member($request), "Set up {$project->code}: {$project->name}");
        // Where its tasks go: a folder with a "Tasks" list.
        WorkStructure::setUpProject($project, $this->member($request));

        // The manager is on the team from the start.
        if ($project->manager_id !== null) {
            ProjectMember::query()->firstOrCreate(['project_id' => $project->id, 'person_id' => $project->manager_id], ['role' => 'Project manager']);
        }

        return to_route('projects.show', $project)->with('success', "{$project->code} set up: add its tasks and team.");
    }

    public function show(Request $request, Project $project): Response
    {
        $metrics = ProjectMetrics::for($project);
        $finance = $this->can($request, 'projects.finance.view');
        $milestones = $project->milestones()->withCount(['tasks', 'tasks as done_count' => fn ($query) => $query->where('status', Task::DONE)])->get();

        return Inertia::render('portal/projects/show', [
            'project' => $this->projectHeader($request, $project),
            'description' => $project->description,
            'metrics' => [
                ...$metrics,
                'budgetMinor' => $finance ? $project->budget_minor : null,
                'labourMinor' => $finance ? $metrics['labourMinor'] : null,
                'expensesMinor' => $finance ? $metrics['expensesMinor'] : null,
                'actualMinor' => $finance ? $metrics['actualMinor'] : null,
            ],
            'byStatus' => collect(Task::STATUSES)->mapWithKeys(fn (string $status) => [
                $status => Task::query()->where('project_id', $project->id)->where('status', $status)->count(),
            ]),
            'milestones' => $milestones->map(fn (Milestone $milestone) => [
                'id' => $milestone->id,
                'name' => $milestone->name,
                'dueOn' => $milestone->due_on->toDateString(),
                'completedAt' => $milestone->completed_at?->toIso8601String(),
                'tasks' => (int) $milestone->getAttribute('tasks_count'),
                'done' => (int) $milestone->getAttribute('done_count'),
                'late' => $milestone->completed_at === null && $milestone->due_on->lt(today()),
            ]),
            'topRisks' => $project->risks()->where('status', '!=', Risk::CLOSED)->get()
                ->sortByDesc(fn (Risk $risk) => $risk->score())->take(3)->values()
                ->map(fn (Risk $risk) => ['id' => $risk->id, 'title' => $risk->title, 'score' => $risk->score(), 'level' => $risk->level()]),
            'upcoming' => $project->tasks()->where('status', '!=', Task::DONE)->whereNotNull('due_on')
                ->with('assignee:id,name')->orderBy('due_on')->limit(6)->get()
                ->map(fn (Task $task) => [
                    'id' => $task->id,
                    'number' => $task->number,
                    'title' => $task->title,
                    'dueOn' => $task->due_on?->toDateString(),
                    'overdue' => $task->overdue(),
                    'assignee' => $task->assignee?->name,
                ]),
            'activity' => $project->activities()->with('user:id,name')->limit(15)->get()
                ->map(fn (Activity $activity) => [
                    'id' => $activity->id,
                    'description' => $activity->description,
                    'by' => $activity->user->name,
                    'at' => $activity->created_at->toIso8601String(),
                ]),
            'statuses' => Project::STATUSES,
        ]);
    }

    public function edit(Project $project): Response
    {
        return Inertia::render('portal/projects/form', [
            'project' => [
                'id' => $project->id,
                'code' => $project->code,
                'name' => $project->name,
                'description' => $project->description,
                'client' => $project->client,
                'managerId' => $project->manager_id,
                'programmeId' => $project->programme_id,
                'startOn' => $project->start_on?->toDateString(),
                'dueOn' => $project->due_on?->toDateString(),
                'budget' => Money::toMajor($project->budget_minor, $project->currency),
                'hasBudgetLines' => $project->budgetVersions()->exists(),
            ],
            'people' => Person::options(),
            'programmes' => $this->programmes($project),
        ]);
    }

    public function update(Request $request, Project $project): RedirectResponse
    {
        $this->save($request, $project);
        ActivityLog::record($project, $this->member($request), 'Changed the project details');

        return to_route('projects.show', $project)->with('success', "{$project->code} saved.");
    }

    /**
     * Moves the project along: active, on hold, completed, cancelled...
     */
    public function status(Request $request, Project $project): RedirectResponse
    {
        $validated = $request->validate(['status' => ['required', Rule::in(Project::STATUSES)]]);

        DB::transaction(function () use ($project, $validated) {
            Project::query()->whereKey($project->id)->lockForUpdate()->first();
            $closing = in_array($validated['status'], [Project::COMPLETED, Project::CANCELLED], true);
            if ($closing && $project->expenses()->whereIn('status', [ExpenseStatus::Requested, ExpenseStatus::Approved])->exists()) {
                throw ValidationException::withMessages(['status' => 'Spending on this project is still awaiting approval or payment: pay, reject or release it first.']);
            }

            $project->forceFill([
                'status' => $validated['status'],
                'completed_at' => $validated['status'] === Project::COMPLETED ? ($project->completed_at ?? now()) : null,
            ])->save();
        });
        ActivityLog::record($project, $this->member($request), 'Marked the project '.str_replace('_', ' ', $project->status));

        return back()->with('success', "{$project->code} is ".str_replace('_', ' ', $project->status).'.');
    }

    /**
     * Programmes a project can go in, and the one it is in even if archived.
     *
     * @return list<array{value: string, label: string}>
     */
    private function programmes(?Project $project = null): array
    {
        return array_values(Programme::query()
            ->where(fn ($query) => $query->whereNull('archived_at')->when($project?->programme_id, fn ($query, $id) => $query->orWhere('id', $id)))
            ->orderBy('code')->get()
            ->map(fn (Programme $programme) => ['value' => $programme->id, 'label' => $programme->label()])->all());
    }

    private function save(Request $request, Project $project): void
    {
        $request->merge(['code' => mb_strtoupper($request->string('code')->trim()->toString())]);
        $tenant = $project->organization_id ?? app(CurrentOrganization::class)->id();

        $validated = $request->validate([
            'code' => [
                'required', 'string', 'regex:/^[A-Z][A-Z0-9]{1,9}$/',
                Rule::unique('projects', 'code')->where('organization_id', $tenant)->ignore($project->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'client' => ['nullable', 'string', 'max:255'],
            'manager_id' => ['nullable', 'uuid', Rule::exists('people', 'id')->where('organization_id', $tenant)],
            'start_on' => ['nullable', 'date'],
            'due_on' => ['nullable', 'date', 'after_or_equal:start_on'],
            'budget' => ['nullable', 'string', Money::rule($project->currency)],
            'programme_id' => ['nullable', 'uuid', Rule::exists('programmes', 'id')->where('organization_id', $tenant)],
        ], ['code.regex' => 'A letter, then up to 9 letters or digits: it starts every task number (WEB-12).']);

        // Once tasks are numbered, the code stays.
        if ($project->exists && $validated['code'] !== $project->code && $project->task_sequence > 0) {
            $validated['code'] = $project->code;
        }

        $project->fill([
            'code' => $validated['code'],
            'name' => trim($validated['name']),
            'description' => $this->text($validated['description'] ?? null),
            'client' => $this->text($validated['client'] ?? null),
            'manager_id' => $validated['manager_id'] ?? null,
            'start_on' => $validated['start_on'] ?? null,
            'due_on' => $validated['due_on'] ?? null,
            'status' => $project->status ?? Project::PLANNING,
        ]);
        // With a budget version, the budget is its total (App\Actions\Budgets).
        if (! $project->exists || ! $project->budgetVersions()->exists()) {
            $project->budget_minor = blank($validated['budget'] ?? null) ? 0 : Money::toMinor((string) $validated['budget'], $project->currency);
        }
        $project->save();

        // Only when the form says: other saves leave its programme alone.
        if ($request->has('programme_id')) {
            $programmeId = $validated['programme_id'] ?? null;
            Programmes::place($project, $programmeId === null ? null : Programme::query()->findOrFail((string) $programmeId), $this->member($request));
        }
    }
}
