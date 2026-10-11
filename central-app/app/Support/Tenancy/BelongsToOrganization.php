<?php

namespace App\Support\Tenancy;

use App\Modules\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marks a model as tenant-owned.
 *
 * Reads are scoped to the current tenant and fail closed without one. Writes
 * take organization_id from the tenant context and refuse to write into a
 * different organization. organization_id is never mass assignable.
 *
 * @mixin Model
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            $context = app(TenantContext::class);

            if ($model->getAttribute('organization_id') === null) {
                $model->setAttribute('organization_id', $context->require()->getKey());

                return;
            }

            if ($context->has() && $model->getAttribute('organization_id') !== $context->id()) {
                throw new CrossTenantWrite($model::class);
            }
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('organization_id')) {
                throw new CrossTenantWrite($model::class);
            }
        });
    }

    public function initializeBelongsToOrganization(): void
    {
        $this->mergeGuarded(['organization_id']);
    }

    /**
     * Explicit, greppable opt-out of the tenant scope for code that legitimately
     * works across organizations (e.g. "list my organizations").
     *
     * @return Builder<static>
     */
    public static function acrossTenants(): Builder
    {
        return static::query()->withoutGlobalScope(TenantScope::class);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
