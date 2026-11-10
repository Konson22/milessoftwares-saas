<?php

namespace App\Access;

use App\Models\OrganizationMembership;

/**
 * A membership's access as last seen from the platform: kept on the
 * membership by App\Actions\Organizations::enter on every visit. For work
 * without a request (reminders, "who can approve this"); a request itself
 * always uses the platform's answer.
 */
final class AccessResolver
{
    public function for(OrganizationMembership $membership): Access
    {
        $active = $membership->isActive();

        return new Access(
            organizationId: $membership->organization_id,
            membershipId: $membership->id,
            isOwner: $active && $membership->is_owner,
            permissions: $active ? ($membership->permissions ?? []) : [],
            roles: $active ? ($membership->roles ?? []) : [],
        );
    }
}
