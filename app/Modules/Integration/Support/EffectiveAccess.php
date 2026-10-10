<?php

namespace App\Modules\Integration\Support;

use App\Modules\Applications\Models\Application;
use App\Modules\Applications\Models\ApplicationCatalogVersion;
use App\Modules\Applications\Models\ApplicationPermission;
use App\Modules\Authorization\Models\RoleAssignment;
use App\Modules\Authorization\Support\Scope;
use App\Modules\Entitlements\Models\Entitlement;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Organizations\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;

/**
 * The answer products act on: is this person, in this organization, allowed
 * to use this application, and with which roles at which scopes? Products
 * then check record-level scope (branch, department) in their own backend.
 *
 * Cached for at most 60 seconds per organization version; any access,
 * entitlement, membership or organization change bumps the version.
 */
final class EffectiveAccess
{
    public const TTL_SECONDS = 60;

    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Organization $organization, string $subject, Application $application): array
    {
        $key = sprintf('effective-access:%s:%d:%s:%s', $organization->id, IntegrationEvents::cacheVersion($organization->id), $application->id, $subject);

        return Cache::remember($key, self::TTL_SECONDS, fn () => $this->tenant->run($organization, fn () => $this->compute($organization, $subject, $application)));
    }

    /**
     * @return array<string, mixed>
     */
    private function compute(Organization $organization, string $subject, Application $application): array
    {
        $entitlement = Entitlement::query()
            ->current()
            ->where('application_id', $application->id)
            ->whereNull('feature_key')
            ->orderByRaw('valid_until IS NULL DESC, valid_until DESC')
            ->first();

        $features = Entitlement::query()
            ->current()
            ->where('application_id', $application->id)
            ->whereNotNull('feature_key')
            ->get(['feature_key', 'limit_value'])
            ->map(fn (Entitlement $feature) => ['key' => $feature->feature_key, 'limit' => $feature->limit_value])
            ->values()
            ->all();

        $membership = OrganizationMembership::query()->where('user_id', $subject)->first();
        $memberActive = $membership !== null && $membership->isActive();
        $organizationActive = $organization->status->value === 'active';

        $roles = ! $memberActive ? [] : RoleAssignment::query()
            ->live()
            ->where('membership_id', $membership->id)
            ->where('application_id', $application->id)
            ->with('role.permissions:id,key', 'role.catalogVersion:id,version')
            ->get()
            ->map(fn (RoleAssignment $assignment) => [
                'role' => $assignment->role->key,
                'catalog_version' => $assignment->role->catalogVersion->version,
                'scope' => array_filter(Scope::of($assignment)->attributes(), fn ($value) => $value !== null),
                'permissions' => array_values($assignment->role->permissions->map(fn (ApplicationPermission $permission) => $permission->key)->sort()->values()->all()),
                'expires_at' => $assignment->expires_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $isOwner = $membership !== null && $organization->owner_membership_id === $membership->id;

        return [
            'organization' => [
                'id' => $organization->id,
                'slug' => $organization->primarySlug?->slug,
                'status' => $organization->status->value,
            ],
            'application' => [
                'code' => $application->code,
                'catalog_version' => ApplicationCatalogVersion::query()->whereKey($application->current_catalog_version_id)->value('version'),
            ],
            'subject' => $subject,
            'entitlement' => [
                'active' => $entitlement !== null,
                'source' => $entitlement?->source,
                'valid_until' => $entitlement?->valid_until?->toIso8601String(),
                'features' => $features,
            ],
            'membership' => $membership === null ? null : [
                'id' => $membership->id,
                'status' => $membership->status->value,
                'is_owner' => $isOwner,
            ],
            // The single yes/no products should gate on before looking at roles.
            'allowed' => $entitlement !== null && $memberActive && $organizationActive && ($roles !== [] || $isOwner),
            'roles' => $roles,
            'generated_at' => now()->toIso8601String(),
            'max_age' => self::TTL_SECONDS,
        ];
    }
}
