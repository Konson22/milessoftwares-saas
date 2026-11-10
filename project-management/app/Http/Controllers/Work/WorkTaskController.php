<?php

namespace App\Http\Controllers\Work;

use App\Actions\Inbox;
use App\Actions\Tasks;
use App\Actions\WorkStructure;
use App\Enums\TaskPriority;
use App\Http\Controllers\Controller;
use App\Models\ListStatus;
use App\Models\Person;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskComment;
use App\Models\TaskList;
use App\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tasks that belong to no project: an organization's day-to-day work, kept
 * in lists. They follow the same rules as a project's tasks (App\Actions\Tasks).
 * A project's task isn't reached from here, and one of these isn't reached
 * through a project.
 */
class WorkTaskController extends Controller
{
    public function show(Request $request, Task $task): Response
    {
        $this->outsideProjects($task);
        $task->load(['assignee:id,name', 'listStatus:id,name', 'list', 'parent:id,number,title', 'subtasks.assignee:id,name', 'checklistItems.completer:id,name', 'comments.author:id,name', 'creator:id,name']);

        return Inertia::render('portal/work/task', [
            'task' => [
                'id' => $task->id,
                'number' => $task->number,
                'title' => $task->title,
                'description' => $task->description,
                'status' => $task->status,
                'statusLabel' => $task->listStatus->name ?? Tasks::label($task->status),
                'statusValue' => $task->list_status_id ?? $task->status,
                'priority' => $task->priority->value,
                'assignee' => $task->assignee?->name,
                'assigneeId' => $task->assignee_id,
                'milestoneId' => null,
                'startOn' => $task->start_on?->toDateString(),
                'dueOn' => $task->due_on?->toDateString(),
                'overdue' => $task->overdue(),
                'estimateHours' => $task->estimate_hours === null ? null : (float) $task->estimate_hours,
                'list' => ['id' => $task->list_id, 'name' => $task->list->name],
                'parent' => $task->parent === null ? null : ['id' => $task->parent->id, 'number' => $task->parent->number, 'title' => $task->parent->title],
                'createdBy' => $task->creator->name,
                'subtasks' => $task->subtasks->map(fn (Task $subtask) => [
                    'id' => $subtask->id,
                    'number' => $subtask->number,
                    'title' => $subtask->title,
                    'status' => Tasks::label($subtask->status),
                    'assignee' => $subtask->assignee?->name,
                ])->values(),
                'checklist' => $task->checklistItems->map(fn (TaskChecklistItem $item) => [
                    'id' => $item->id,
                    'body' => $item->body,
                    'done' => $item->done_at !== null,
                    'doneBy' => $item->completer?->name,
                ])->values(),
                'comments' => $task->comments->map(fn (TaskComment $comment) => [
                    'id' => $comment->id,
                    'body' => $comment->body,
                    'author' => $comment->author->name,
                    'at' => $comment->created_at->toIso8601String(),
                ])->values(),
            ],
            'statusOptions' => Tasks::statusOptions($task),
            'lists' => TaskList::query()->active()->whereNull('project_id')->orderBy('name')->get(['id', 'name'])
                ->map(fn (TaskList $list) => ['value' => $list->id, 'label' => $list->name])->values()->all(),
            'people' => Person::options(),
            'milestones' => [],
            'priorities' => TaskPriority::options(),
            'mentionable' => Inbox::reachable()->pluck('name')->unique()->values()->all(),
            'canManage' => $this->can($request, 'projects.tasks.manage'),
        ]);
    }

    public function store(Request $request, TaskList $list, Tasks $tasks): RedirectResponse
    {
        $task = $tasks->createInList($list, $this->details($request), $this->member($request));

        return back()->with('success', "{$task->number} added.");
    }

    public function update(Request $request, Task $task, Tasks $tasks): RedirectResponse
    {
        $this->outsideProjects($task);
        $by = $this->member($request);
        $tasks->ensureTaskOpen($task, 'title');
        $lead = $task->assignee_id;
        $task->fill($this->details($request))->save();
        if ($task->assignee_id !== $lead) {
            $tasks->tellAssignee($task, $by);
        }

        $listId = $request->string('list_id')->toString();
        if ($listId !== '' && $listId !== $task->list_id) {
            $list = TaskList::query()->find($listId) ?? throw ValidationException::withMessages(['list_id' => 'Choose a list outside projects.']);
            WorkStructure::moveTask($task, $list, $by);
        }

        return back()->with('success', "{$task->number} saved.");
    }

    /**
     * To a status: one of the list's own, or a plain category.
     */
    public function move(Request $request, Task $task, Tasks $tasks): RedirectResponse
    {
        $this->outsideProjects($task);
        $validated = $request->validate(['to' => ['required', 'string', 'max:36']]);

        if (in_array($validated['to'], Task::STATUSES, true)) {
            $tasks->move($task, $validated['to'], null, $this->member($request));
        } else {
            $named = ListStatus::query()->where('list_id', $task->list_id)->where('id', $validated['to'])->first()
                ?? throw ValidationException::withMessages(['to' => 'Choose one of this list\'s statuses.']);
            $tasks->move($task, $named->category, null, $this->member($request), $named);
        }

        return back();
    }

    public function comment(Request $request, Task $task, Tasks $tasks): RedirectResponse
    {
        $this->outsideProjects($task);
        $validated = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        $tasks->addComment($task, trim($validated['body']), $this->member($request));

        return back()->with('success', 'Comment added.');
    }

    public function addSubtask(Request $request, Task $task, Tasks $tasks): RedirectResponse
    {
        $this->outsideProjects($task);
        $validated = $request->validate(['title' => ['required', 'string', 'max:255']]);

        $subtask = $tasks->addSubtask($task, ['title' => trim($validated['title']), 'priority' => $task->priority->value], $this->member($request));

        return back()->with('success', "{$subtask->number} added.");
    }

    public function addChecklistItem(Request $request, Task $task, Tasks $tasks): RedirectResponse
    {
        $this->outsideProjects($task);
        $validated = $request->validate(['body' => ['required', 'string', 'max:255']]);

        $tasks->addChecklistItem($task, trim($validated['body']));

        return back();
    }

    public function toggleChecklistItem(Request $request, Task $task, TaskChecklistItem $checklistItem, Tasks $tasks): RedirectResponse
    {
        $this->outsideProjects($task);
        $tasks->toggleChecklistItem($task, $checklistItem, $this->member($request));

        return back();
    }

    public function removeChecklistItem(Task $task, TaskChecklistItem $checklistItem, Tasks $tasks): RedirectResponse
    {
        $this->outsideProjects($task);
        $tasks->removeChecklistItem($task, $checklistItem);

        return back();
    }

    private function outsideProjects(Task $task): void
    {
        abort_unless($task->project_id === null, 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function details(Request $request): array
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'priority' => ['required', Rule::enum(TaskPriority::class)],
            'assignee_id' => ['nullable', 'uuid', Rule::exists('people', 'id')->where('organization_id', app(CurrentOrganization::class)->id())],
            'start_on' => ['nullable', 'date'],
            'due_on' => ['nullable', 'date', 'after_or_equal:start_on'],
            'estimate_hours' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'list_id' => ['nullable', 'uuid'],
        ]);

        return [
            'title' => trim($validated['title']),
            'description' => $this->text($validated['description'] ?? null),
            'priority' => $validated['priority'],
            'assignee_id' => $validated['assignee_id'] ?? null,
            'start_on' => $validated['start_on'] ?? null,
            'due_on' => $validated['due_on'] ?? null,
            'estimate_hours' => $validated['estimate_hours'] ?? null,
        ];
    }
}
