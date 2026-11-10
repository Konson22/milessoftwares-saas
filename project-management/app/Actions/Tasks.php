<?php

namespace App\Actions;

use App\Audit\Audit;
use App\Models\ListStatus;
use App\Models\Person;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\TaskChecklistItem;
use App\Models\TaskCollaborator;
use App\Models\TaskComment;
use App\Models\TaskDependency;
use App\Models\TaskList;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\ActivityLog;
use App\Support\DocumentNumbers;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Tasks: numbered per project (CODE-1, CODE-2...), or across the organization
 * when they belong to no project (WORK-1...), moved across the board,
 * and waiting on one another. A task cannot be done while a task it
 * depends on is not, and dependencies never go round in a circle.
 *
 * Subtasks are tasks with a parent, one level deep, in the parent's project
 * and list. A parent cannot be done while a subtask is open, and a subtask
 * cannot reopen under a parent that is done. Project progress counts only
 * top-level tasks (App\Support\ProjectMetrics).
 */
class Tasks
{
    public const DISK = 'local';

    /**
     * @param  array<string, mixed>  $details  the task's columns
     *
     * @throws ValidationException
     */
    public function create(Project $project, array $details, User $by): Task
    {
        $this->ensureOpen($project, 'title');

        return DB::transaction(function () use ($project, $details, $by) {
            $locked = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            // In the list asked for (one of the project's), or its first.
            $listId = isset($details['list_id']) ? (string) $details['list_id'] : null;
            $details['list_id'] = WorkStructure::listFor($locked, $listId, $by)->id;
            $sequence = $locked->task_sequence + 1;
            $locked->forceFill(['task_sequence' => $sequence])->save();

            $status = (string) ($details['status'] ?? Task::TODO);
            $task = Task::query()->create([
                ...$details,
                'project_id' => $locked->id,
                'number' => "{$locked->code}-{$sequence}",
                'status' => $status,
                'list_status_id' => ListStatus::firstFor((string) $details['list_id'], $status)?->id,
                'position' => $this->nextPosition($locked, $status),
                'created_by' => $by->id,
                'completed_at' => $status === Task::DONE ? now() : null,
            ]);
            ActivityLog::record($locked, $by, "Added {$task->number}: {$task->title}");
            $this->tellAssignee($task, $by);

            return $task;
        });
    }

    /**
     * A task in a list that belongs to no project.
     *
     * @param  array<string, mixed>  $details  the task's columns
     *
     * @throws ValidationException
     */
    public function createInList(TaskList $list, array $details, User $by): Task
    {
        if ($list->project_id !== null) {
            throw ValidationException::withMessages(['title' => "{$list->name} belongs to a project: add the task there."]);
        }
        if ($list->isArchived()) {
            throw ValidationException::withMessages(['title' => "{$list->name} is archived."]);
        }

        return DB::transaction(function () use ($list, $details, $by) {
            $status = (string) ($details['status'] ?? Task::TODO);
            $task = Task::query()->create([
                ...$details,
                'project_id' => null,
                'list_id' => $list->id,
                'number' => app(DocumentNumbers::class)->next('work_task', 'WORK', 1),
                'status' => $status,
                'list_status_id' => ListStatus::firstFor($list->id, $status)?->id,
                'position' => (int) Task::query()->where('list_id', $list->id)->where('status', $status)->max('position') + 1,
                'created_by' => $by->id,
                'completed_at' => $status === Task::DONE ? now() : null,
            ]);
            Audit::record('work.task_created', $task, ['number' => $task->number, 'title' => $task->title, 'list' => $list->name], actor: $by);
            $this->tellAssignee($task, $by);

            return $task;
        });
    }

    /**
     * Moves a task to a column of the board, at a place in it (0 = top), or
     * to one of its list's own statuses, whose category is then the column.
     * Moving by category alone keeps the name within the category, and takes
     * the list's first for that category otherwise.
     *
     * @throws ValidationException
     */
    public function move(Task $task, string $status, ?int $position, User $by, ?ListStatus $named = null): void
    {
        DB::transaction(function () use ($task, $status, $position, $by, $named) {
            $locked = Task::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();
            $this->ensureTaskOpen($locked, 'status');

            if ($status === Task::DONE && $locked->status !== Task::DONE) {
                $waiting = $locked->dependencies()->where('status', '!=', Task::DONE)->pluck('number');
                if ($waiting->isNotEmpty()) {
                    throw ValidationException::withMessages(['status' => "{$locked->number} waits for ".$waiting->join(', ').'.']);
                }
                $open = $locked->subtasks()->where('status', '!=', Task::DONE)->pluck('number');
                if ($open->isNotEmpty()) {
                    throw ValidationException::withMessages(['status' => "{$locked->number} still has open subtasks: ".$open->join(', ').'.']);
                }
            }
            if ($status !== Task::DONE && $locked->status === Task::DONE && $locked->parent?->isDone()) {
                throw ValidationException::withMessages(['status' => "{$locked->parent->number} is done: reopen it first."]);
            }

            // Ordered among its own kind: the board shows top-level tasks, so
            // hidden subtasks must not shift the place it was dropped at.
            $column = Task::query()->where('project_id', $locked->project_id)->where('status', $status)
                ->when($locked->project_id === null, fn ($query) => $query->where('list_id', $locked->list_id))
                ->where('parent_id', $locked->parent_id)
                ->whereKeyNot($locked->id)->orderBy('position')->pluck('id')->all();
            $at = $position === null ? count($column) : max(0, min($position, count($column)));
            array_splice($column, $at, 0, [$locked->id]);

            $moved = $locked->status !== $status;
            $name = $named ?? ($moved ? ListStatus::firstFor($locked->list_id, $status) : $locked->listStatus);
            $moved = $moved || $name?->id !== $locked->list_status_id;
            $locked->forceFill([
                'status' => $status,
                'list_status_id' => $name?->id,
                'completed_at' => $status === Task::DONE ? ($locked->completed_at ?? now()) : null,
            ])->save();
            foreach ($column as $index => $id) {
                Task::query()->whereKey($id)->update(['position' => $index]);
            }

            if ($moved) {
                $this->log($locked, $by, "Moved {$locked->number} to ".($name->name ?? self::label($status)));
            }
        });
    }

    /**
     * Makes $task wait for $dependsOn (both of the same project).
     *
     * @throws ValidationException
     */
    public function addDependency(Task $task, Task $dependsOn, User $by): void
    {
        if ($dependsOn->project_id !== $task->project_id) {
            throw ValidationException::withMessages(['depends_on_id' => 'Choose a task of the same project.']);
        }
        if ($dependsOn->id === $task->id || $this->reaches($dependsOn->id, $task->id)) {
            throw ValidationException::withMessages(['depends_on_id' => "{$dependsOn->number} already waits for {$task->number}: that would be a circle."]);
        }

        TaskDependency::query()->firstOrCreate(['task_id' => $task->id, 'depends_on_id' => $dependsOn->id]);
        $this->log($task, $by, "{$task->number} now waits for {$dependsOn->number}");
    }

    public function removeDependency(Task $task, Task $dependsOn): void
    {
        TaskDependency::query()->where('task_id', $task->id)->where('depends_on_id', $dependsOn->id)->delete();
    }

    /**
     * A comment. Whoever it mentions hears of that; the task's lead and
     * collaborators who aren't mentioned hear of a comment.
     */
    public function addComment(Task $task, string $body, User $by): TaskComment
    {
        return DB::transaction(function () use ($task, $body, $by) {
            $comment = $task->comments()->create(['user_id' => $by->id, 'body' => $body]);
            $this->log($task, $by, "Commented on {$task->number}");

            $mentioned = Inbox::mentionedIn($body);
            Inbox::notify($task, UserNotification::MENTIONED, $mentioned, $by, $body);

            $onTask = $task->collaborators()->get();
            if ($task->assignee !== null) {
                $onTask->push($task->assignee);
            }
            Inbox::notify($task, UserNotification::COMMENTED, $onTask->whereNotIn('id', $mentioned->pluck('id'))->all(), $by, $body);

            return $comment;
        });
    }

    /**
     * Tells a task's lead they have it.
     */
    public function tellAssignee(Task $task, User $by): void
    {
        $assignee = $task->assignee_id === null ? null : Person::query()->whereKey($task->assignee_id)->first();
        if ($assignee !== null) {
            Inbox::notify($task, UserNotification::ASSIGNED, [$assignee], $by);
        }
    }

    /**
     * A task under another, in its list and milestone.
     *
     * @param  array<string, mixed>  $details  the task's columns
     *
     * @throws ValidationException
     */
    public function addSubtask(Task $parent, array $details, User $by): Task
    {
        if ($parent->parent_id !== null) {
            throw ValidationException::withMessages(['title' => "{$parent->number} is a subtask: subtasks go one level deep."]);
        }
        if ($parent->isDone()) {
            throw ValidationException::withMessages(['title' => "{$parent->number} is done: reopen it to add to it."]);
        }

        $project = $parent->project;
        $under = [...$details, 'parent_id' => $parent->id, 'list_id' => $parent->list_id, 'milestone_id' => $parent->milestone_id];

        return $project === null ? $this->createInList($parent->list, $under, $by) : $this->create($project, $under, $by);
    }

    /**
     * @throws ValidationException
     */
    public function addCollaborator(Task $task, Person $person, User $by): void
    {
        $this->ensureTaskOpen($task, 'person_id');
        if ($task->assignee_id === $person->id) {
            throw ValidationException::withMessages(['person_id' => "{$person->name} already leads {$task->number}."]);
        }

        $collaborator = TaskCollaborator::query()->firstOrCreate(['task_id' => $task->id, 'person_id' => $person->id]);
        if ($collaborator->wasRecentlyCreated) {
            Inbox::notify($task, UserNotification::ADDED, [$person], $by);
        }
        $this->log($task, $by, "{$person->name} joined {$task->number}");
    }

    /**
     * @throws ValidationException
     */
    public function removeCollaborator(Task $task, Person $person, User $by): void
    {
        $this->ensureTaskOpen($task, 'person_id');
        TaskCollaborator::query()->where('task_id', $task->id)->where('person_id', $person->id)->delete();
        $this->log($task, $by, "{$person->name} left {$task->number}");
    }

    /**
     * @throws ValidationException
     */
    public function addChecklistItem(Task $task, string $body): TaskChecklistItem
    {
        $this->ensureTaskOpen($task, 'body');

        return $task->checklistItems()->create([
            'body' => $body,
            'position' => (int) $task->checklistItems()->max('position') + 1,
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function toggleChecklistItem(Task $task, TaskChecklistItem $item, User $by): void
    {
        $this->ensureTaskOpen($task, 'body');
        $done = $item->done_at === null;
        $item->forceFill(['done_at' => $done ? now() : null, 'done_by' => $done ? $by->id : null])->save();
    }

    /**
     * @throws ValidationException
     */
    public function removeChecklistItem(Task $task, TaskChecklistItem $item): void
    {
        $this->ensureTaskOpen($task, 'body');
        $item->delete();
    }

    /**
     * @throws ValidationException
     */
    public function attach(Task $task, UploadedFile $file, User $by): TaskAttachment
    {
        $this->ensureTaskOpen($task, 'file');

        $path = $file->store("organizations/{$task->organization_id}/tasks/{$task->id}", self::DISK);
        if ($path === false) {
            throw ValidationException::withMessages(['file' => 'The file could not be stored.']);
        }

        $attachment = $task->attachments()->create([
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'path' => $path,
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'size' => (int) $file->getSize(),
            'uploaded_by' => $by->id,
        ]);
        Audit::record('task.file_attached', $task, ['number' => $task->number, 'file' => $attachment->original_name, 'size' => $attachment->size], actor: $by);

        return $attachment;
    }

    /**
     * @throws ValidationException
     */
    public function detach(Task $task, TaskAttachment $attachment, User $by): void
    {
        $this->ensureTaskOpen($task, 'file');

        $attachment->delete();
        Storage::disk(self::DISK)->delete($attachment->path);
        Audit::record('task.file_removed', $task, ['number' => $task->number, 'file' => $attachment->original_name], actor: $by);
        $this->log($task, $by, "Removed {$attachment->original_name} from {$task->number}");
    }

    public static function label(string $status): string
    {
        return match ($status) {
            Task::TODO => 'To do',
            Task::IN_PROGRESS => 'In progress',
            Task::REVIEW => 'In review',
            Task::DONE => 'Done',
            default => $status,
        };
    }

    /**
     * Where a task can go: its list's own statuses, and the plain category
     * wherever the list names nothing (or the task has no name yet).
     *
     * @return list<array{value: string, label: string}>
     */
    public static function statusOptions(Task $task): array
    {
        $statuses = ListStatus::query()->where('list_id', $task->list_id)->orderBy('position')->get();

        $options = [];
        foreach (Task::STATUSES as $category) {
            $named = $statuses->where('category', $category);
            if ($named->isEmpty() || ($task->status === $category && $task->list_status_id === null)) {
                $options[] = ['value' => $category, 'label' => self::label($category)];
            }
            foreach ($named as $status) {
                $options[] = ['value' => $status->id, 'label' => $status->name];
            }
        }

        return $options;
    }

    /**
     * Whether a task can be changed: its project is open, or its list isn't
     * archived when it has no project.
     *
     * @throws ValidationException
     */
    public function ensureTaskOpen(Task $task, string $field): void
    {
        $project = $task->project;
        if ($project !== null) {
            $this->ensureOpen($project, $field);
        } elseif ($task->list->isArchived()) {
            throw ValidationException::withMessages([$field => "{$task->list->name} is archived."]);
        }
    }

    /**
     * What happened to a task: in its project's activity log, or the audit
     * log when it has no project.
     */
    private function log(Task $task, User $by, string $description): void
    {
        $project = $task->project;
        if ($project !== null) {
            ActivityLog::record($project, $by, $description);
        } else {
            Audit::record('work.task_changed', $task, ['number' => $task->number, 'change' => $description], actor: $by);
        }
    }

    /**
     * Whether task $from waits, directly or through others, for task $to.
     */
    private function reaches(string $from, string $to): bool
    {
        $seen = [];
        $queue = [$from];
        while ($queue !== []) {
            $current = array_shift($queue);
            if ($current === $to) {
                return true;
            }
            if (isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;
            foreach (TaskDependency::query()->where('task_id', $current)->pluck('depends_on_id') as $next) {
                $queue[] = (string) $next;
            }
        }

        return false;
    }

    private function nextPosition(Project $project, string $status): int
    {
        return (int) Task::query()->where('project_id', $project->id)->where('status', $status)->max('position') + 1;
    }

    /**
     * @throws ValidationException
     */
    private function ensureOpen(Project $project, string $field): void
    {
        if (! $project->isOpen()) {
            throw ValidationException::withMessages([$field => "{$project->code} is {$project->status}: reopen it to change its tasks."]);
        }
    }
}
