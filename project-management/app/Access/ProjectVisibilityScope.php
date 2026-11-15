<?php

namespace App\Access;

use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Narrows projects to those the signed-in member may see
 * (App\Access\ProjectVisibility). A no-op for everyone who sees every
 * project, so nothing changes for them.
 *
 * @implements Scope<Project>
 */
final class ProjectVisibilityScope implements Scope
{
    /**
     * @param  Builder<covariant Project>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $visibility = app(ProjectVisibility::class);
        if ($visibility->restricted()) {
            $visibility->apply($builder);
        }
    }
}
