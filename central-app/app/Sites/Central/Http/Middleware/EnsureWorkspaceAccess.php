<?php

namespace App\Sites\Central\Http\Middleware;

use App\Modules\Memberships\Enums\MembershipStatus;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Organizations\Enums\OrganizationStatus;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires an authenticated user with an active membership in the resolved
 * tenant. Runs after ResolveTenant and the auth middleware.
 */
class EnsureWorkspaceAccess
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $organization = $this->tenant->require();
        $user = $request->user();

        $membership = $user === null ? null : OrganizationMembership::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->first();

        if ($membership === null || $membership->status !== MembershipStatus::Active) {
            abort(403);
        }

        // Status is only disclosed to members.
        if ($organization->status === OrganizationStatus::Suspended) {
            return Inertia::render('central/workspace/suspended', [
                'organization' => ['displayName' => $organization->display_name],
            ])->toResponse($request)->setStatusCode(403);
        }

        $request->attributes->set('membership', $membership);

        return $next($request);
    }
}
