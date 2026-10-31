<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The team directory: everyone who works on projects, how loaded they are,
 * and what an hour of their time costs.
 */
class PeopleController extends Controller
{
    public function index(Request $request): Response
    {
        $people = Person::query()->orderByDesc('active')->orderBy('name')->get();
        $load = ProjectMember::query()->whereHas('project', fn ($query) => $query->whereIn('status', Project::OPEN))
            ->groupBy('person_id')->selectRaw('person_id, sum(allocation_percent) as total, count(*) as projects')
            ->get()->keyBy('person_id');
        $openTasks = Task::query()->where('status', '!=', Task::DONE)->whereNotNull('assignee_id')
            ->groupBy('assignee_id')->selectRaw('assignee_id, count(*) as open')->pluck('open', 'assignee_id');
        $finance = $this->can($request, 'projects.finance.view');

        return Inertia::render('people/index', [
            'people' => $people->map(fn (Person $person) => [
                'id' => $person->id,
                'name' => $person->name,
                'email' => $person->email,
                'title' => $person->title,
                'member' => $person->user_id !== null,
                'active' => $person->active,
                'projects' => (int) ($load->get($person->id)?->getAttribute('projects') ?? 0),
                'allocation' => (int) ($load->get($person->id)?->getAttribute('total') ?? 0),
                'openTasks' => (int) ($openTasks[$person->id] ?? 0),
                'hourlyCostMinor' => $finance ? $person->hourly_cost_minor : null,
            ]),
            'canManage' => $this->can($request, 'projects.people.manage'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('people/form', ['person' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $person = new Person;
        $this->save($request, $person);

        return to_route('people.index')->with('success', "{$person->name} added.");
    }

    public function edit(Person $person): Response
    {
        return Inertia::render('people/form', [
            'person' => [
                'id' => $person->id,
                'name' => $person->name,
                'email' => $person->email,
                'title' => $person->title,
                'member' => $person->user_id !== null,
                'hourlyCost' => Money::toMajor($person->hourly_cost_minor),
                'active' => $person->active,
            ],
        ]);
    }

    public function update(Request $request, Person $person): RedirectResponse
    {
        $this->save($request, $person);

        return to_route('people.index')->with('success', "{$person->name} saved.");
    }

    private function save(Request $request, Person $person): void
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'hourly_cost' => ['nullable', 'string', Money::rule()],
            'active' => ['boolean'],
        ]);

        // Members' names and emails come from the platform (RememberPerson).
        if ($person->user_id === null) {
            $person->fill(['name' => trim($validated['name']), 'email' => $this->text($validated['email'] ?? null)]);
        }
        $person->fill([
            'title' => $this->text($validated['title'] ?? null),
            'hourly_cost_minor' => blank($validated['hourly_cost'] ?? null) ? 0 : Money::toMinor((string) $validated['hourly_cost']),
            'active' => (bool) ($validated['active'] ?? true),
        ])->save();
    }
}
