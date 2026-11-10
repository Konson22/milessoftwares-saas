<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Marks a model as owned by an organization (an organization_id uuid
 * column).
 *
 * Reads are scoped to the current organization and fail closed without
 * one. New records take organization_id from the current organization;
 * writing into, or moving a record to, another organization is refused.
 * organization_id is never mass assignable.
 *
 * @mixin Model
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new OrganizationScope);

        static::creating(function (Model $model): void {
            $current = app(CurrentOrganization::class)->require();

            if ($model->getAttribute('organization_id') === null) {
                $model->setAttribute('organization_id', $current->id);

                return;
            }

            if ($model->getAttribute('organization_id') !== $current->id) {
                throw new CrossOrganizationWrite($model::class);
            }
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('organization_id')) {
                throw new CrossOrganizationWrite($model::class);
            }
        });
    }

    public function initializeBelongsToOrganization(): void
    {
        $this->mergeGuarded(['organization_id']);
    }

    /**
     * Explicit, greppable opt-out of the organization scope for code that
     * works across organizations on purpose (cross-organization reports, jobs that fan
     * out per organization).
     *
     * @return Builder<static>
     */
    public static function acrossOrganizations(): Builder
    {
        return static::query()->withoutGlobalScope(OrganizationScope::class);
    }
}
