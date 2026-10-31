<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Support\ProjectMetrics;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The portfolio: how every open project stands, worst first, and the
 * member's own work.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $me = Person::forUser($this->member($request));
        $projects = $this->can($request, 'projects.view')
            ? Project::query()->whereIn('status', Project::OPEN)->with('manager:id,name')->get()
            : collect();
        $metrics = ProjectMetrics::forProjects($projects);
        $rank = [ProjectMetrics::OFF_TRACK => 0, ProjectMetrics::AT_RISK => 1, ProjectMetrics::ON_TRACK => 2];
        $finance = $this->can($request, 'projects.finance.view');

        $myTasks = $me === null ? collect() : Task::query()->where('assignee_id', $me->id)->where('status', '!=', Task::DONE)
            ->whereHas('project', fn ($query) => $query->whereIn('status', Project::OPEN))
            ->with('project:id,code,name')->orderByRaw('due_on is null')->orderBy('due_on')->limit(8)->get();

        return Inertia::render('home', [
            'portfolio' => $projects->isEmpty() && ! $this->can($request, 'projects.view') ? null : [
                'active' => $projects->where('status', Project::ACTIVE)->count(),
                'open' => $projects->count(),
                'atRisk' => collect($metrics)->where('health', ProjectMetrics::AT_RISK)->count(),
                'offTrack' => collect($metrics)->where('health', ProjectMetrics::OFF_TRACK)->count(),
                'overdueTasks' => collect($metrics)->sum('overdueTasks'),
                'budgetMinor' => $finance ? (int) $projects->sum('budget_minor') : null,
                'actualMinor' => $finance ? (int) collect($metrics)->sum('actualMinor') : null,
                'projects' => $projects
                    ->sortBy(fn (Project $project) => [$rank[$metrics[$project->id]['health']], $project->due_on?->getTimestamp() ?? PHP_INT_MAX])
                    ->values()->take(10)
                    ->map(fn (Project $project) => [
                        'id' => $project->id,
                        'code' => $project->code,
                        'name' => $project->name,
                        'manager' => $project->manager?->name,
                        'status' => $project->status,
                        'dueOn' => $project->due_on?->toDateString(),
                        'progress' => $metrics[$project->id]['progress'],
                        'health' => $metrics[$project->id]['health'],
                        'healthReasons' => $metrics[$project->id]['healthReasons'],
                    ]),
            ],
            'myTasks' => $myTasks->map(fn (Task $task) => [
                'id' => $task->id,
                'projectId' => $task->project_id,
                'project' => $task->project->code,
                'number' => $task->number,
                'title' => $task->title,
                'status' => $task->status,
                'priority' => $task->priority->value,
                'dueOn' => $task->due_on?->toDateString(),
                'overdue' => $task->overdue(),
            ]),
            'myMinutesThisWeek' => $me === null ? 0 : (int) TimeEntry::query()->where('person_id', $me->id)
                ->whereBetween('worked_on', [today()->startOfWeek(), today()->endOfWeek()])->sum('minutes'),
        ]);
    }
}
