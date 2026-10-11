<?php

namespace Miles\PlatformClient\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Marks a model as tenant-owned (needs an organization_id uuid column).
 *
 * Reads are scoped to the current tenant and fail closed without one. New
 * records take organization_id from the current tenant; writing into, or
 * moving a record to, another organization is refused. organization_id is
 * never mass assignable.
 *
 * @mixin Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            $tenant = app(CurrentTenant::class);

            if ($model->getAttribute('organization_id') === null) {
                $model->setAttribute('organization_id', $tenant->require()->id);

                return;
            }

            if ($model->getAttribute('organization_id') !== $tenant->require()->id) {
                throw new CrossTenantWrite($model::class);
            }
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('organization_id')) {
                throw new CrossTenantWrite($model::class);
            }
        });
    }

    public function initializeBelongsToTenant(): void
    {
        $this->mergeGuarded(['organization_id']);
    }

    /**
     * Explicit, greppable opt-out of the tenant scope for code that works
     * across organizations on purpose (reports for platform staff, jobs that
     * fan out per tenant).
     *
     * @return Builder<static>
     */
    public static function acrossTenants(): Builder
    {
        return static::query()->withoutGlobalScope(TenantScope::class);
    }
}
