<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ShowsProject;
use App\Models\Milestone;
use App\Models\Person;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Support\ActivityLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Tenancy\CurrentTenant;

/**
 * A project's team (who, in what role, how much of their time) and its
 * milestones.
 */
class TeamController extends Controller
{
    use ShowsProject;

    public function show(Request $request, Project $project): Response
    {
        $members = $project->members()->with('person')->get()->sortBy('person.name');
        $openTasks = Task::query()->where('project_id', $project->id)->where('status', '!=', Task::DONE)
            ->groupBy('assignee_id')->selectRaw('assignee_id, count(*) as open')->pluck('open', 'assignee_id');
        $hours = TimeEntry::query()->where('project_id', $project->id)
            ->groupBy('person_id')->selectRaw('person_id, sum(minutes) as minutes')->pluck('minutes', 'person_id');
        // What each person is committed to across every open project.
        $load = ProjectMember::query()->whereHas('project', fn ($query) => $query->whereIn('status', Project::OPEN))
            ->groupBy('person_id')->selectRaw('person_id, sum(allocation_percent) as total')->pluck('total', 'person_id');

        return Inertia::render('projects/team', [
            'project' => $this->projectHeader($request, $project),
            'members' => $members->values()->map(fn (ProjectMember $member) => [
                'id' => $member->id,
                'personId' => $member->person_id,
                'name' => $member->person->name,
                'title' => $member->person->title,
                'role' => $member->role,
                'allocation' => $member->allocation_percent,
                'totalAllocation' => (int) ($load[$member->person_id] ?? 0),
                'openTasks' => (int) ($openTasks[$member->person_id] ?? 0),
                'minutes' => (int) ($hours[$member->person_id] ?? 0),
            ]),
            'people' => collect(Person::options())->reject(fn (array $option) => $members->contains('person_id', $option['value']))->values(),
            'milestones' => $project->milestones()
                ->withCount(['tasks', 'tasks as done_count' => fn ($query) => $query->where('status', Task::DONE)])
                ->get()->map(fn (Milestone $milestone) => [
                    'id' => $milestone->id,
                    'name' => $milestone->name,
                    'dueOn' => $milestone->due_on->toDateString(),
                    'completedAt' => $milestone->completed_at?->toIso8601String(),
                    'tasks' => (int) $milestone->getAttribute('tasks_count'),
                    'done' => (int) $milestone->getAttribute('done_count'),
                    'late' => $milestone->completed_at === null && $milestone->due_on->lt(today()),
                ]),
        ]);
    }

    public function addMember(Request $request, Project $project): RedirectResponse
    {
        $validated = $request->validate([
            'person_id' => ['required', 'uuid', Rule::exists('people', 'id')->where('organization_id', app(CurrentTenant::class)->id())],
            'role' => ['nullable', 'string', 'max:64'],
            'allocation_percent' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        if ($project->members()->where('person_id', $validated['person_id'])->exists()) {
            throw ValidationException::withMessages(['person_id' => 'Already on the team.']);
        }
        $member = $project->members()->create([
            'person_id' => $validated['person_id'],
            'role' => $this->text($validated['role'] ?? null),
            'allocation_percent' => (int) $validated['allocation_percent'],
        ]);
        ActivityLog::record($project, $this->member($request), "Added {$member->person->name} to the team");

        return back()->with('success', "{$member->person->name} is on the team.");
    }

    public function removeMember(Request $request, Project $project, ProjectMember $member): RedirectResponse
    {
        abort_unless($member->project_id === $project->id, 404);

        $member->delete();
        ActivityLog::record($project, $this->member($request), "Took {$member->person->name} off the team");

        return back()->with('success', "{$member->person->name} is off the team.");
    }

    public function addMilestone(Request $request, Project $project): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'due_on' => ['required', 'date'],
        ]);

        $milestone = $project->milestones()->create(['name' => trim($validated['name']), 'due_on' => $validated['due_on']]);
        ActivityLog::record($project, $this->member($request), "Added milestone {$milestone->name}");

        return back()->with('success', "Milestone {$milestone->name} added.");
    }

    /**
     * Reached (or not reached after all).
     */
    public function toggleMilestone(Request $request, Project $project, Milestone $milestone): RedirectResponse
    {
        abort_unless($milestone->project_id === $project->id, 404);

        $milestone->forceFill(['completed_at' => $milestone->completed_at === null ? now() : null])->save();
        ActivityLog::record($project, $this->member($request), ($milestone->completed_at === null ? 'Reopened' : 'Reached').' milestone '.$milestone->name);

        return back()->with('success', "Milestone {$milestone->name} ".($milestone->completed_at === null ? 'reopened.' : 'reached.'));
    }

    public function removeMilestone(Request $request, Project $project, Milestone $milestone): RedirectResponse
    {
        abort_unless($milestone->project_id === $project->id, 404);

        Task::query()->where('milestone_id', $milestone->id)->update(['milestone_id' => null]);
        $milestone->delete();
        ActivityLog::record($project, $this->member($request), "Removed milestone {$milestone->name}");

        return back()->with('success', "Milestone {$milestone->name} removed.");
    }
}
