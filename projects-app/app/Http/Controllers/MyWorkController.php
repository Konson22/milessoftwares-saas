<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The member's own work: their open tasks across projects, and their
 * timesheet for a week.
 */
class MyWorkController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $me = Person::forUser($this->member($request));
        $week = CarbonImmutable::parse(is_string($request->query('week')) ? $request->query('week') : 'today')->startOfWeek();

        $tasks = $me === null ? collect() : Task::query()->where('assignee_id', $me->id)->where('status', '!=', Task::DONE)
            ->whereHas('project', fn ($query) => $query->whereIn('status', Project::OPEN))
            ->with('project:id,code,name')
            ->orderByRaw('due_on is null')->orderBy('due_on')->get();
        $entries = $me === null ? collect() : TimeEntry::query()->where('person_id', $me->id)
            ->whereBetween('worked_on', [$week, $week->endOfWeek()])
            ->with(['project:id,code,name', 'task:id,number,title'])->orderBy('worked_on')->get();

        return Inertia::render('my-work', [
            'tasks' => $tasks->map(fn (Task $task) => [
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
            'week' => [
                'start' => $week->toDateString(),
                'previous' => $week->subWeek()->toDateString(),
                'next' => $week->addWeek()->toDateString(),
                'days' => collect(range(0, 6))->map(fn (int $day) => $week->addDays($day)->toDateString()),
            ],
            'entries' => $entries->map(fn (TimeEntry $entry) => [
                'id' => $entry->id,
                'projectId' => $entry->project_id,
                'project' => $entry->project->code,
                'task' => $entry->task?->number,
                'workedOn' => $entry->worked_on->toDateString(),
                'minutes' => $entry->minutes,
                'note' => $entry->note,
            ]),
            // Projects time can go to: open ones, and the ones on this sheet.
            'projects' => Project::query()->whereIn('status', Project::OPEN)->orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn (Project $project) => ['value' => $project->id, 'label' => "{$project->code} · {$project->name}"]),
            'meId' => $me?->id,
            'canLog' => $this->can($request, 'projects.time.log') && $me !== null,
            'today' => today()->toDateString(),
        ]);
    }
}
