<?php

namespace App\Actions;

use App\Audit\Audit;
use App\Models\Folder;
use App\Models\ListStatus;
use App\Models\Project;
use App\Models\Space;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The rules of the work structure, Workspace → Space → (Folder) → List:
 * - Nothing is deleted: parts are archived, and only once nothing active
 *   is left inside (a list: no open task; a project keeps one list).
 * - Nothing is added inside an archived part.
 * - A list holds one project's tasks, or tasks of no project; a task moves
 *   only between lists of the same kind.
 * - Every project has a list: a new one gets a folder and a "Tasks" list
 *   in the organization's first space (made on first use).
 * Every change is audited.
 */
final class WorkStructure
{
    public const KINDS = ['workspace' => Workspace::class, 'space' => Space::class, 'folder' => Folder::class, 'list' => TaskList::class];

    public static function setUpProject(Project $project, User $by): TaskList
    {
        return DB::transaction(function () use ($project, $by) {
            $space = self::defaultSpace($by);
            $folder = self::createFolder($space, mb_substr("{$project->code} · {$project->name}", 0, 120), $by);

            return self::createList($space, $folder, $project, 'Tasks', $by);
        });
    }

    /**
     * The list a new task of the project goes in: the one asked for, or the
     * project's first.
     *
     * @throws ValidationException
     */
    public static function listFor(Project $project, ?string $listId, User $by): TaskList
    {
        if ($listId !== null) {
            $list = $project->lists()->active()->whereKey($listId)->first();
            if ($list === null) {
                throw ValidationException::withMessages(['list_id' => "Choose one of {$project->code}'s lists."]);
            }

            return $list;
        }

        return $project->lists()->active()->first() ?? self::setUpProject($project, $by);
    }

    /**
     * @throws ValidationException
     */
    public static function moveTask(Task $task, TaskList $list, User $by): void
    {
        if ($list->project_id !== $task->project_id || $list->isArchived()) {
            $project = $task->project;
            throw ValidationException::withMessages(['list_id' => $project === null ? 'Choose a list outside projects.' : "Choose one of {$project->code}'s lists."]);
        }
        if ($task->list_id === $list->id) {
            return;
        }
        if ($task->parent_id !== null) {
            throw ValidationException::withMessages(['list_id' => "{$task->number} is a subtask: it stays in its parent's list."]);
        }

        $from = $task->list_id;
        // Each keeps its category: the new list's first name for it, if any.
        foreach ([$task, ...$task->subtasks()->get()->all()] as $moving) {
            $moving->forceFill(['list_id' => $list->id, 'list_status_id' => ListStatus::firstFor($list->id, $moving->status)?->id])->save();
        }
        Audit::record('work.task_moved', $task, ['number' => $task->number, 'from_list' => $from, 'to_list' => $list->id], actor: $by);
    }

    public static function createWorkspace(string $name, User $by): Workspace
    {
        $workspace = Workspace::query()->create([
            'name' => $name,
            'position' => self::next(Workspace::query()),
            'created_by' => $by->id,
        ]);
        self::audit('created', $workspace, $by);

        return $workspace;
    }

    /**
     * @throws ValidationException
     */
    public static function createSpace(Workspace $workspace, string $name, User $by): Space
    {
        self::requireActive($workspace, 'workspace');
        $space = $workspace->spaces()->create([
            'name' => $name,
            'position' => self::next($workspace->spaces()->getQuery()),
            'created_by' => $by->id,
        ]);
        self::audit('created', $space, $by);

        return $space;
    }

    /**
     * @throws ValidationException
     */
    public static function createFolder(Space $space, string $name, User $by): Folder
    {
        self::requireActive($space, 'space');
        $folder = $space->folders()->create([
            'name' => $name,
            'position' => self::next($space->folders()->getQuery()),
            'created_by' => $by->id,
        ]);
        self::audit('created', $folder, $by);

        return $folder;
    }

    /**
     * @throws ValidationException
     */
    public static function createList(Space $space, ?Folder $folder, ?Project $project, string $name, User $by): TaskList
    {
        self::requireActive($space, 'space');
        if ($folder !== null) {
            self::requireActive($folder, 'folder');
            if ($folder->space_id !== $space->id) {
                throw ValidationException::withMessages(['folder_id' => 'That folder is in another space.']);
            }
        }

        $list = TaskList::query()->create([
            'space_id' => $space->id,
            'folder_id' => $folder?->id,
            'project_id' => $project?->id,
            'name' => $name,
            'position' => self::next(TaskList::query()->where('space_id', $space->id)->where('folder_id', $folder?->id)),
            'created_by' => $by->id,
        ]);
        self::audit('created', $list, $by, ['project' => $project?->code]);

        return $list;
    }

    public static function rename(Workspace|Space|Folder|TaskList $part, string $name, User $by): void
    {
        $before = $part->name;
        $part->forceFill(['name' => $name])->save();
        self::audit('renamed', $part, $by, ['before' => $before]);
    }

    /**
     * @throws ValidationException
     */
    public static function archive(Workspace|Space|Folder|TaskList $part, User $by): void
    {
        DB::transaction(function () use ($part, $by) {
            if ($part->isArchived()) {
                return;
            }

            $blocker = match (true) {
                $part instanceof Workspace => $part->spaces()->active()->exists() ? 'Archive its spaces first.' : null,
                $part instanceof Space => $part->folders()->active()->exists() || $part->lists()->active()->exists() ? 'Archive its folders and lists first.' : null,
                $part instanceof Folder => $part->lists()->active()->exists() ? 'Archive its lists first.' : null,
                $part instanceof TaskList => self::listBlocker($part),
            };
            if ($blocker !== null) {
                throw ValidationException::withMessages(['structure' => $blocker]);
            }

            $part->forceFill(['archived_at' => now()])->save();
            self::audit('archived', $part, $by);
        });
    }

    /**
     * The organization's first active space, made on first use (a "Main"
     * workspace with a "Projects" space).
     */
    private static function defaultSpace(User $by): Space
    {
        $space = Space::query()->active()
            ->join('workspaces', 'workspaces.id', '=', 'spaces.workspace_id')
            ->whereNull('workspaces.archived_at')
            ->orderBy('workspaces.position')->orderBy('spaces.position')
            ->select('spaces.*')->first();

        return $space ?? self::createSpace(self::createWorkspace('Main', $by), 'Projects', $by);
    }

    private static function listBlocker(TaskList $list): ?string
    {
        $open = $list->tasks()->where('status', '!=', Task::DONE)->count();
        if ($open > 0) {
            return $open === 1 ? '1 task in it is not done: move or finish it first.' : "{$open} tasks in it are not done: move or finish them first.";
        }

        $project = $list->project;
        if ($project === null) {
            return null;
        }
        $others = TaskList::query()->active()->where('project_id', $project->id)->whereKeyNot($list->id)->exists();

        return $others ? null : "It is {$project->code}'s only list.";
    }

    /**
     * @throws ValidationException
     */
    private static function requireActive(Workspace|Space|Folder $parent, string $kind): void
    {
        if ($parent->isArchived()) {
            throw ValidationException::withMessages(['structure' => "That {$kind} is archived."]);
        }
    }

    /**
     * @param  Builder<covariant Model>  $siblings
     */
    private static function next(Builder $siblings): int
    {
        return (int) $siblings->max('position') + 1;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private static function audit(string $verb, Workspace|Space|Folder|TaskList $part, User $by, array $extra = []): void
    {
        $kind = (string) array_search($part::class, self::KINDS, true);
        Audit::record("work.{$kind}_{$verb}", $part, ['name' => $part->name, ...$extra], actor: $by);
    }
}
