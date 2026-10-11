<?php

namespace Miles\PlatformClient\Tenancy;

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
        $organizationId = app(CurrentTenant::class)->id()
            ?? throw new MissingTenant(sprintf(
                'Query on %s requires a tenant. Use %s::acrossTenants() only where cross-tenant access is intended.',
                $model::class,
                class_basename($model),
            ));

        $builder->where($model->qualifyColumn('organization_id'), $organizationId);
    }
}
