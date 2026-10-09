<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * @template TModel of Model
 *
 * @implements Scope<TModel>
 */
final class TenantScope implements Scope
{
    /**
     * @param  Builder<covariant TModel>  $builder
     * @param  TModel  $model
     */
    public function apply(Builder $builder, Model $model): void
    {
        $organizationId = app(TenantContext::class)->id()
            ?? throw new MissingTenantContext(sprintf(
                'Query on %s requires a tenant context. Use %s::acrossTenants() only where cross-tenant access is intended.',
                $model::class,
                class_basename($model),
            ));

        $builder->where($model->qualifyColumn('organization_id'), $organizationId);
    }
}
