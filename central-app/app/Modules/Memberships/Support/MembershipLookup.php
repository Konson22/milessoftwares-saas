<?php

namespace App\Modules\Memberships\Support;

use App\Models\User;
use App\Modules\Memberships\Enums\MembershipStatus;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Organizations\Enums\OrganizationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Cross-tenant membership reads for a single user ("which organizations am I
 * in?"). These are the only legitimate acrossTenants() reads of memberships,
 * and they are always filtered to the given user.
 */
final class MembershipLookup
{
    /**
     * Active memberships in organizations whose workspace resolves.
     *
     * @return Collection<int, OrganizationMembership>
     */
    public function activeFor(User $user): Collection
    {
        return $this->active($user)->orderBy('joined_at')->get();
    }

    /**
     * The user's active membership in one organization, or null. Used on every
     * request that acts for an organization, so a removed or suspended member
     * loses access immediately.
     */
    public function activeIn(User $user, string $organizationId): ?OrganizationMembership
    {
        return $this->active($user)->where('organization_id', $organizationId)->first();
    }

    /**
     * @return Builder<OrganizationMembership>
     */
    private function active(User $user): Builder
    {
        return OrganizationMembership::acrossTenants()
            ->where('user_id', $user->getKey())
            ->where('status', MembershipStatus::Active)
            ->whereHas('organization', fn (Builder $query) => $query->whereIn('status', [
                OrganizationStatus::Active->value,
                OrganizationStatus::Suspended->value,
            ]))
            ->with('organization.primarySlug');
    }
}
