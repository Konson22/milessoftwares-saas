<?php

namespace App\Access;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Narrows a project's records to those whose project the member may see
 * (App\Access\VisibleThroughProject). A no-op for everyone who sees every
 * project.
 *
 * @template TModel of Model
 *
 * @implements Scope<TModel>
 */
final class VisibleThroughProjectScope implements Scope
{
    /**
     * @param  Builder<covariant TModel>  $builder
     * @param  TModel  $model
     */
    public function apply(Builder $builder, Model $model): void
    {
        if (! $model instanceof ProjectBound || ! app(ProjectVisibility::class)->restricted()) {
            return;
        }
        [$column, $parent] = $model->visibleThrough();
        $qualified = $model->qualifyColumn($column);
        $parentModel = new $parent;
        $builder->where(fn (Builder $query) => $query->whereNull($qualified)
            ->orWhereIn($qualified, $parentModel->newQuery()->select($parentModel->qualifyColumn('id'))));
    }
}
