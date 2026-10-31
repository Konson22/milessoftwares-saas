<?php

namespace App\Actions;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Models\User;
use App\Support\ActivityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tasks: numbered per project (CODE-1, CODE-2...), moved across the board,
 * and waiting on one another. A task cannot be done while a task it
 * depends on is not, and dependencies never go round in a circle.
 */
class Tasks
{
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
            $sequence = $locked->task_sequence + 1;
            $locked->forceFill(['task_sequence' => $sequence])->save();

            $status = (string) ($details['status'] ?? Task::TODO);
            $task = Task::query()->create([
                ...$details,
                'project_id' => $locked->id,
                'number' => "{$locked->code}-{$sequence}",
                'status' => $status,
                'position' => $this->nextPosition($locked, $status),
                'created_by' => $by->id,
                'completed_at' => $status === Task::DONE ? now() : null,
            ]);
            ActivityLog::record($locked, $by, "Added {$task->number}: {$task->title}");

            return $task;
        });
    }

    /**
     * Moves a task to a column of the board, at a place in it (0 = top).
     *
     * @throws ValidationException
     */
    public function move(Task $task, string $status, ?int $position, User $by): void
    {
        DB::transaction(function () use ($task, $status, $position, $by) {
            $locked = Task::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();
            $project = $locked->project;
            $this->ensureOpen($project, 'status');

            if ($status === Task::DONE && $locked->status !== Task::DONE) {
                $waiting = $locked->dependencies()->where('status', '!=', Task::DONE)->pluck('number');
                if ($waiting->isNotEmpty()) {
                    throw ValidationException::withMessages(['status' => "{$locked->number} waits for ".$waiting->join(', ').'.']);
                }
            }

            $column = Task::query()->where('project_id', $locked->project_id)->where('status', $status)
                ->whereKeyNot($locked->id)->orderBy('position')->pluck('id')->all();
            $at = $position === null ? count($column) : max(0, min($position, count($column)));
            array_splice($column, $at, 0, [$locked->id]);

            $moved = $locked->status !== $status;
            $locked->forceFill([
                'status' => $status,
                'completed_at' => $status === Task::DONE ? ($locked->completed_at ?? now()) : null,
            ])->save();
            foreach ($column as $index => $id) {
                Task::query()->whereKey($id)->update(['position' => $index]);
            }

            if ($moved) {
                ActivityLog::record($project, $by, "Moved {$locked->number} to ".self::label($status));
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
        ActivityLog::record($task->project, $by, "{$task->number} now waits for {$dependsOn->number}");
    }

    public function removeDependency(Task $task, Task $dependsOn): void
    {
        TaskDependency::query()->where('task_id', $task->id)->where('depends_on_id', $dependsOn->id)->delete();
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
