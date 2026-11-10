<?php

namespace App\Http\Controllers\Work;

use App\Actions\Tasks;
use App\Enums\TaskPriority;
use App\Http\Controllers\Controller;
use App\Models\ListStatus;
use App\Models\Milestone;
use App\Models\Person;
use App\Models\Task;
use App\Models\TaskList;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One list's tasks, by status, with where the list sits and a form to add
 * a task to it.
 */
class ListController extends Controller
{
    public function show(Request $request, TaskList $list): Response
    {
        $list->load(['space.workspace:id,name', 'folder:id,name', 'project']);
        $project = $list->project;
        $statuses = $list->statuses()->withCount('tasks')->get();
        $tasks = $list->tasks()->topLevel()->with('assignee:id,name')
            ->orderBy('status')->orderBy('position')->get();

        return Inertia::render('portal/work/list', [
            'list' => [
                'id' => $list->id,
                'name' => $list->name,
                'archived' => $list->isArchived(),
                'path' => array_values(array_filter([$list->space->workspace->name, $list->space->name, $list->folder?->name])),
                'project' => $project === null ? null : ['id' => $project->id, 'code' => $project->code, 'name' => $project->name],
            ],
            'columns' => $this->columns($tasks, $statuses),
            'statuses' => $statuses->map(fn (ListStatus $status) => [
                'id' => $status->id,
                'name' => $status->name,
                'category' => $status->category,
                'tasks' => (int) $status->getAttribute('tasks_count'),
            ])->values()->all(),
            'canManageStatuses' => $this->can($request, 'work.structure.manage') && ! $list->isArchived(),
            'canAdd' => $this->can($request, 'projects.tasks.manage') && ($project === null || $project->isOpen()) && ! $list->isArchived(),
            'people' => Person::options(),
            'milestones' => $project === null ? [] : $project->milestones()->get()->map(fn (Milestone $milestone) => ['value' => $milestone->id, 'label' => $milestone->name]),
            'priorities' => TaskPriority::options(),
        ]);
    }

    /**
     * A column per named status, in category order. A category gets its own
     * column when the list names nothing for it, or tasks in it have no name.
     *
     * @param  Collection<int, Task>  $tasks
     * @param  Collection<int, ListStatus>  $statuses
     * @return list<array<string, mixed>>
     */
    private function columns(Collection $tasks, Collection $statuses): array
    {
        $columns = [];
        foreach (Task::STATUSES as $category) {
            $named = $statuses->where('category', $category);
            $unnamed = $tasks->where('status', $category)->whereNull('list_status_id');
            if ($named->isEmpty() || $unnamed->isNotEmpty()) {
                $columns[] = $this->column($category, $category, Tasks::label($category), $unnamed);
            }
            foreach ($named as $status) {
                $columns[] = $this->column($status->id, $category, $status->name, $tasks->where('list_status_id', $status->id));
            }
        }

        return $columns;
    }

    /**
     * @param  Collection<int, Task>  $tasks
     * @return array<string, mixed>
     */
    private function column(string $key, string $category, string $label, Collection $tasks): array
    {
        return [
            'key' => $key,
            'status' => $category,
            'label' => $label,
            'tasks' => array_values($tasks->map(fn (Task $task) => [
                'id' => $task->id,
                'number' => $task->number,
                'title' => $task->title,
                'priority' => $task->priority->value,
                'assignee' => $task->assignee?->name,
                'dueOn' => $task->due_on?->toDateString(),
                'overdue' => $task->overdue(),
            ])->all()),
        ];
    }
}
