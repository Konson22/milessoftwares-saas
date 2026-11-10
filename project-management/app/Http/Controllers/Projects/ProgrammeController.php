<?php

namespace App\Http\Controllers\Projects;

use App\Actions\Programmes;
use App\Http\Controllers\Controller;
use App\Models\Person;
use App\Models\Programme;
use App\Models\Project;
use App\Support\ProjectMetrics;
use App\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Programmes: groups of projects, and how the projects in each stand.
 * Budget and spending show only to those who may see a project's.
 */
class ProgrammeController extends Controller
{
    public function index(Request $request): Response
    {
        $programmes = Programme::query()
            ->with('manager:id,name')
            ->withCount(['projects', 'projects as open_count' => fn ($query) => $query->whereIn('status', Project::OPEN)])
            ->orderByRaw('archived_at is not null')->orderBy('code')->get();

        return Inertia::render('portal/programmes/index', [
            'programmes' => $programmes->map(fn (Programme $programme) => [
                'id' => $programme->id,
                'code' => $programme->code,
                'name' => $programme->name,
                'manager' => $programme->manager?->name,
                'projects' => (int) $programme->getAttribute('projects_count'),
                'open' => (int) $programme->getAttribute('open_count'),
                'archived' => $programme->isArchived(),
            ]),
            'people' => Person::options(),
            'canManage' => $this->can($request, 'projects.manage'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $programme = Programmes::create($this->validated($request, null), $this->member($request));

        return to_route('programmes.show', $programme)->with('success', "{$programme->code} added: put its projects in it.");
    }

    public function show(Request $request, Programme $programme): Response
    {
        $programme->load('manager:id,name');
        $projects = $programme->projects()->with('manager:id,name')->orderBy('code')->get();
        $metrics = ProjectMetrics::forProjects($projects);
        $finance = $this->can($request, 'projects.finance.view');

        // Amounts add up per currency: a programme can span more than one.
        // Money spent stays counted, whatever became of the project; the
        // budget of a cancelled project will not be spent, so it does not.
        $totals = $projects->groupBy('currency')->map(fn ($group, string $currency) => [
            'currency' => $currency,
            'budgetMinor' => (int) $group->reject(fn (Project $project) => $project->status === Project::CANCELLED)->sum('budget_minor'),
            'actualMinor' => (int) $group->sum(fn (Project $project) => $metrics[$project->id]['actualMinor']),
            'cancelledActualMinor' => (int) $group->where('status', Project::CANCELLED)->sum(fn (Project $project) => $metrics[$project->id]['actualMinor']),
        ])->values();

        return Inertia::render('portal/programmes/show', [
            'programme' => [
                'id' => $programme->id,
                'code' => $programme->code,
                'name' => $programme->name,
                'description' => $programme->description,
                'manager' => $programme->manager?->name,
                'managerId' => $programme->manager_id,
                'archived' => $programme->isArchived(),
            ],
            'projects' => $projects->map(fn (Project $project) => [
                'id' => $project->id,
                'code' => $project->code,
                'name' => $project->name,
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
            'summary' => [
                'projects' => $projects->count(),
                'open' => $projects->filter(fn (Project $project) => $project->isOpen())->count(),
                'overdueTasks' => (int) $projects->sum(fn (Project $project) => $metrics[$project->id]['overdueTasks']),
            ],
            'totals' => $finance ? $totals : null,
            // Projects that can be put in it: those in no programme yet.
            'available' => Project::query()->whereNull('programme_id')->whereIn('status', Project::OPEN)->orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn (Project $project) => ['value' => $project->id, 'label' => "{$project->code} · {$project->name}"])->values()->all(),
            'people' => Person::options(),
            'canManage' => $this->can($request, 'projects.manage'),
        ]);
    }

    public function update(Request $request, Programme $programme): RedirectResponse
    {
        Programmes::update($programme, $this->validated($request, $programme), $this->member($request));

        return back()->with('success', "{$programme->code} saved.");
    }

    public function archive(Request $request, Programme $programme): RedirectResponse
    {
        Programmes::archive($programme, $this->member($request));

        return back()->with('success', "{$programme->code} archived.");
    }

    public function restore(Request $request, Programme $programme): RedirectResponse
    {
        Programmes::restore($programme, $this->member($request));

        return back()->with('success', "{$programme->code} restored.");
    }

    public function addProject(Request $request, Programme $programme): RedirectResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'uuid', Rule::exists('projects', 'id')->where('organization_id', app(CurrentOrganization::class)->id())],
        ]);
        $project = Project::query()->findOrFail((string) $validated['project_id']);

        Programmes::place($project, $programme, $this->member($request));

        return back()->with('success', "{$project->code} is now in {$programme->code}.");
    }

    public function removeProject(Request $request, Programme $programme, Project $project): RedirectResponse
    {
        abort_unless($project->programme_id === $programme->id, 404);

        Programmes::place($project, null, $this->member($request));

        return back()->with('success', "{$project->code} taken out of {$programme->code}.");
    }

    /**
     * @return array{code: string, name: string, description: string|null, manager_id: string|null}
     */
    private function validated(Request $request, ?Programme $programme): array
    {
        $request->merge(['code' => mb_strtoupper($request->string('code')->trim()->toString())]);
        $tenant = app(CurrentOrganization::class)->id();

        $validated = $request->validate([
            'code' => ['required', 'string', 'regex:/^[A-Z][A-Z0-9]{1,9}$/', Rule::unique('programmes', 'code')->where('organization_id', $tenant)->ignore($programme?->id)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'manager_id' => ['nullable', 'uuid', Rule::exists('people', 'id')->where('organization_id', $tenant)],
        ], ['code.regex' => 'A letter, then up to 9 letters or digits.']);

        return [
            'code' => (string) $validated['code'],
            'name' => trim((string) $validated['name']),
            'description' => $this->text($validated['description'] ?? null),
            'manager_id' => $validated['manager_id'] ?? null,
        ];
    }
}
