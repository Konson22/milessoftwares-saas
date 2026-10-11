<?php

namespace App\Modules\Memberships\Support;

use App\Models\User;
use App\Modules\Memberships\Models\OrganizationMembership;
use Illuminate\Http\Request;

/**
 * The organization a user is working in on the app host, kept in the app
 * host's session. The stored id is a preference only: every read re-checks
 * the membership, so switching never grants access by itself.
 */
final class CurrentOrganization
{
    public const SESSION_KEY = 'current_organization_id';

    public function __construct(private readonly MembershipLookup $memberships) {}

    public function resolve(Request $request, User $user): ?OrganizationMembership
    {
        $id = $request->session()->get(self::SESSION_KEY);

        if (! is_string($id)) {
            return null;
        }

        $membership = $this->memberships->activeIn($user, $id);

        if ($membership === null) {
            $request->session()->forget(self::SESSION_KEY);
        }

        return $membership;
    }

    /**
     * Returns null, and changes nothing, when the user is not an active member.
     */
    public function switchTo(Request $request, User $user, string $organizationId): ?OrganizationMembership
    {
        $membership = $this->memberships->activeIn($user, $organizationId);

        if ($membership !== null) {
            $request->session()->put(self::SESSION_KEY, $membership->organization_id);
        }

        return $membership;
    }
}
