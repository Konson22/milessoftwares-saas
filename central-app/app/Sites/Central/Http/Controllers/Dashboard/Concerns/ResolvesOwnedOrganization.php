<?php

namespace App\Sites\Central\Http\Controllers\Dashboard\Concerns;

use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Organizations\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;

/**
 * Organization settings, subscription and billing on app.<root> are the
 * owner's alone until scoped roles arrive in Phase 4.
 */
trait ResolvesOwnedOrganization
{
    private function ownedOrganization(Request $request, TenantContext $tenant): Organization
    {
        $membership = $request->attributes->get('membership');
        $organization = $tenant->organization();

        abort_unless($membership instanceof OrganizationMembership && $organization !== null, 404);
        abort_unless($organization->owner_membership_id === $membership->id, 403);

        return $organization;
    }
}
