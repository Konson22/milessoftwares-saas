<?php

namespace App\Actions;

use App\Audit\Audit;
use App\Models\Programme;
use App\Models\Project;
use App\Models\User;
use App\Support\ActivityLog;
use Illuminate\Validation\ValidationException;

/**
 * The rules of programmes: nothing is deleted, an archived programme takes
 * no new projects, and it is archived only once its projects are finished.
 * A project is in one programme at most. Every change is audited.
 */
final class Programmes
{
    /**
     * @param  array{code: string, name: string, description: string|null, manager_id: string|null}  $details
     */
    public static function create(array $details, User $by): Programme
    {
        $programme = Programme::query()->create([...$details, 'created_by' => $by->id]);
        Audit::record('programme.created', $programme, ['code' => $programme->code, 'name' => $programme->name], actor: $by);

        return $programme;
    }

    /**
     * @param  array{code: string, name: string, description: string|null, manager_id: string|null}  $details
     */
    public static function update(Programme $programme, array $details, User $by): void
    {
        $before = $programme->only(array_keys($details));
        $programme->fill($details)->save();
        Audit::change('programme.updated', $programme, $before, $programme->only(array_keys($details)), actor: $by);
    }

    /**
     * @throws ValidationException
     */
    public static function archive(Programme $programme, User $by): void
    {
        $open = $programme->projects()->whereIn('status', Project::OPEN)->count();
        if ($open > 0) {
            throw ValidationException::withMessages(['programme' => $open === 1 ? '1 project in it is still open: finish it or take it out first.' : "{$open} projects in it are still open: finish them or take them out first."]);
        }

        $programme->forceFill(['archived_at' => now()])->save();
        Audit::record('programme.archived', $programme, ['code' => $programme->code], actor: $by);
    }

    public static function restore(Programme $programme, User $by): void
    {
        $programme->forceFill(['archived_at' => null])->save();
        Audit::record('programme.restored', $programme, ['code' => $programme->code], actor: $by);
    }

    /**
     * Puts a project in a programme, or moves it to another, or (null) takes
     * it out. What it is already in may stay even once archived.
     *
     * @throws ValidationException
     */
    public static function place(Project $project, ?Programme $programme, User $by): void
    {
        if ($project->programme_id === $programme?->id) {
            return;
        }
        if ($programme?->isArchived()) {
            throw ValidationException::withMessages(['programme_id' => "{$programme->code} is archived."]);
        }

        $from = $project->programme_id;
        $project->forceFill(['programme_id' => $programme?->id])->save();

        Audit::record('programme.project_placed', $project, ['project' => $project->code, 'from' => $from, 'to' => $programme?->id], actor: $by);
        ActivityLog::record($project, $by, $programme === null ? 'Took the project out of its programme' : "Put the project in {$programme->code}");
    }
}
