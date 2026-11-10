<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * @template TModel of Model
 *
 * @implements Scope<TModel>
 */
final class OrganizationScope implements Scope
{
    /**
     * @param  Builder<covariant TModel>  $builder
     * @param  TModel  $model
     */
    public function apply(Builder $builder, Model $model): void
    {
        $organizationId = app(CurrentOrganization::class)->id()
            ?? throw new MissingOrganization(sprintf(
                'Query on %s requires an organization. Use %s::acrossOrganizations() only where crossing organizations is intended.',
                $model::class,
                class_basename($model),
            ));

        $builder->where($model->qualifyColumn('organization_id'), $organizationId);
    }
}
