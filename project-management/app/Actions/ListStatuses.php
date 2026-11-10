<?php

namespace App\Actions;

use App\Audit\Audit;
use App\Models\ListStatus;
use App\Models\TaskList;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The rules of a list's own statuses:
 * - Each sits inside one fixed category; a task's status stays the category.
 * - A status with tasks keeps its category: moving tasks between categories
 *   is what Tasks::move() checks, one task at a time.
 * - Removing a status keeps its tasks and their category; they just lose the
 *   name.
 * - An archived list's statuses don't change.
 * Every change is audited.
 */
final class ListStatuses
{
    /**
     * @throws ValidationException
     */
    public static function add(TaskList $list, string $name, string $category, User $by): ListStatus
    {
        self::requireActive($list);

        $status = $list->statuses()->create([
            'name' => $name,
            'category' => $category,
            'position' => (int) $list->statuses()->max('position') + 1,
            'created_by' => $by->id,
        ]);
        Audit::record('work.status_added', $list, ['name' => $name, 'category' => $category], actor: $by);

        return $status;
    }

    /**
     * @throws ValidationException
     */
    public static function update(TaskList $list, ListStatus $status, string $name, string $category, User $by): void
    {
        self::requireActive($list);
        if ($category !== $status->category && $status->tasks()->exists()) {
            throw ValidationException::withMessages(['category' => "{$status->name} has tasks: move them first, or add a new status."]);
        }

        $before = $status->only(['name', 'category']);
        $status->forceFill(['name' => $name, 'category' => $category])->save();
        Audit::record('work.status_changed', $list, ['before' => $before, 'after' => ['name' => $name, 'category' => $category]], actor: $by);
    }

    /**
     * One place up or down among the statuses of its category.
     *
     * @throws ValidationException
     */
    public static function move(TaskList $list, ListStatus $status, bool $up): void
    {
        self::requireActive($list);

        DB::transaction(function () use ($list, $status, $up) {
            $ids = $list->statuses()->where('category', $status->category)->pluck('id')->all();
            $at = (int) array_search($status->id, $ids, true);
            $to = $up ? $at - 1 : $at + 1;
            if (! isset($ids[$to])) {
                return;
            }

            [$ids[$at], $ids[$to]] = [$ids[$to], $ids[$at]];
            $positions = $list->statuses()->where('category', $status->category)->pluck('position')->sort()->values()->all();
            foreach ($ids as $index => $id) {
                ListStatus::query()->whereKey($id)->update(['position' => $positions[$index]]);
            }
        });
    }

    /**
     * @throws ValidationException
     */
    public static function remove(TaskList $list, ListStatus $status, User $by): void
    {
        self::requireActive($list);

        $tasks = $status->tasks()->count();
        $status->delete();
        Audit::record('work.status_removed', $list, ['name' => $status->name, 'category' => $status->category, 'tasks' => $tasks], actor: $by);
    }

    /**
     * @throws ValidationException
     */
    private static function requireActive(TaskList $list): void
    {
        if ($list->isArchived()) {
            throw ValidationException::withMessages(['name' => 'That list is archived.']);
        }
    }
}
