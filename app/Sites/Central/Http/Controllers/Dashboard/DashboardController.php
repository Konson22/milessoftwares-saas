<?php

namespace App\Sites\Central\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Modules\Applications\Support\Launcher;
use App\Modules\Authorization\Support\AccessResolver;
use App\Modules\Authorization\Support\WorkspaceAbilities;
use App\Modules\Memberships\Models\Invitation;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Memberships\Support\MembershipLookup;
use App\Modules\Structure\Models\Branch;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, MembershipLookup $memberships, Launcher $launcher, AccessResolver $access): Response
    {
        $current = $request->attributes->get('membership');
        $current = $current instanceof OrganizationMembership ? $current : null;

        return Inertia::render('central/dashboard', [
            'organizations' => $memberships->activeFor($request->user())
                ->map(fn (OrganizationMembership $membership) => [
                    'id' => $membership->organization_id,
                    'displayName' => $membership->organization->display_name,
                    'status' => $membership->organization->status->value,
                    'workspaceUrl' => $membership->organization->workspaceUrl(),
                    'isOwner' => $membership->organization->owner_membership_id === $membership->id,
                    'isCurrent' => $membership->organization_id === $current?->organization_id,
                ])
                ->values()
                ->all(),
            // Runs inside the current organization's tenant context
            // (ResolveCurrentOrganization), so these queries are scoped to it.
            'current' => $current === null ? null : [
                'displayName' => $current->organization->display_name,
                'workspaceUrl' => $current->organization->workspaceUrl(),
                'applications' => $launcher->for($current),
                'counts' => [
                    'members' => OrganizationMembership::query()->where('status', 'active')->count(),
                    'branches' => Branch::query()->where('status', 'active')->count(),
                    'pendingInvitations' => Invitation::query()->where('status', 'pending')->where('expires_at', '>', now())->count(),
                ],
                'isOwner' => $access->isOwner($current),
                'canManageAccess' => (new WorkspaceAbilities($access))->manageAccess($current),
            ],
        ]);
    }
}
