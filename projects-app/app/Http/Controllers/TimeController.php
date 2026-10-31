<?php

namespace App\Http\Controllers;

use App\Actions\Timesheets;
use App\Http\Controllers\Concerns\ShowsProject;
use App\Models\Person;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Time on a project: everyone's entries, and logging more. Members log
 * their own time; projects.time.manage logs and removes anyone's.
 */
class TimeController extends Controller
{
    use ShowsProject;

    public function show(Request $request, Project $project): Response
    {
        $finance = $this->can($request, 'projects.finance.view');
        $entries = $project->timeEntries()->with(['person:id,name', 'task:id,number,title'])
            ->latest('worked_on')->latest()->limit(300)->get();
        $me = Person::forUser($this->member($request));
        $manages = $this->can($request, 'projects.time.manage');

        return Inertia::render('projects/time', [
            'project' => $this->projectHeader($request, $project),
            'entries' => $entries->map(fn (TimeEntry $entry) => [
                'id' => $entry->id,
                'person' => $entry->person->name,
                'task' => $entry->task === null ? null : ['id' => $entry->task->id, 'number' => $entry->task->number, 'title' => $entry->task->title],
                'workedOn' => $entry->worked_on->toDateString(),
                'minutes' => $entry->minutes,
                'note' => $entry->note,
                'costMinor' => $finance ? $entry->cost_minor : null,
                'canRemove' => $manages || ($me !== null && $entry->person_id === $me->id),
            ]),
            'byPerson' => $project->timeEntries()->join('people', 'people.id', '=', 'time_entries.person_id')
                ->groupBy('people.name')->selectRaw('people.name as name, sum(minutes) as minutes, sum(cost_minor) as cost')
                ->orderByDesc('minutes')->get()
                ->map(fn (TimeEntry $row) => [
                    'name' => (string) $row->getAttribute('name'),
                    'minutes' => (int) $row->getAttribute('minutes'),
                    'costMinor' => $finance ? (int) $row->getAttribute('cost') : null,
                ]),
            'tasks' => $project->tasks()->orderBy('number')->get(['id', 'number', 'title'])
                ->map(fn (Task $task) => ['value' => $task->id, 'label' => "{$task->number} · {$task->title}"]),
            // Whose time can be logged: anyone's for managers, else one's own.
            'people' => $manages ? Person::options() : ($me === null ? [] : [['value' => $me->id, 'label' => $me->name]]),
            'meId' => $me?->id,
            'today' => today()->toDateString(),
        ]);
    }

    public function store(Request $request, Project $project, Timesheets $timesheets): RedirectResponse
    {
        $validated = $request->validate([
            'person_id' => ['required', 'uuid'],
            'task_id' => ['nullable', 'uuid'],
            'worked_on' => ['required', 'date', 'before_or_equal:today'],
            'hours' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $person = Person::query()->whereKey($validated['person_id'])->first() ?? throw ValidationException::withMessages(['person_id' => 'Choose someone of the team.']);
        $this->ensureMayLogFor($request, $person);
        $task = isset($validated['task_id']) ? Task::query()->whereKey($validated['task_id'])->first() : null;
        $minutes = Timesheets::minutes($validated['hours']) ?? throw ValidationException::withMessages(['hours' => 'Hours like 1.5 or 1:30.']);

        $entry = $timesheets->log($project, $task, $person, CarbonImmutable::parse($validated['worked_on']), $minutes, $validated['note'] ?? null, $this->member($request));

        return back()->with('success', sprintf('%d:%02d logged for %s.', intdiv($entry->minutes, 60), $entry->minutes % 60, $person->name));
    }

    public function destroy(Request $request, Project $project, TimeEntry $timeEntry): RedirectResponse
    {
        abort_unless($timeEntry->project_id === $project->id, 404);
        $this->ensureMayLogFor($request, $timeEntry->person);

        $timeEntry->delete();

        return back()->with('success', 'Time entry removed.');
    }

    private function ensureMayLogFor(Request $request, Person $person): void
    {
        if ($this->can($request, 'projects.time.manage')) {
            return;
        }

        abort_unless($person->user_id === $this->member($request)->id, 403, 'You log your own time.');
    }
}
