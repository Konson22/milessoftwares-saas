<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Memberships\Support\MembershipLookup;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, MembershipLookup $memberships): Response
    {
        $current = $request->attributes->get('membership');
        $currentId = $current instanceof OrganizationMembership ? $current->organization_id : null;

        return Inertia::render('dashboard', [
            'organizations' => $memberships->activeFor($request->user())
                ->map(fn (OrganizationMembership $membership) => [
                    'id' => $membership->organization_id,
                    'displayName' => $membership->organization->display_name,
                    'status' => $membership->organization->status->value,
                    'workspaceUrl' => $membership->organization->workspaceUrl(),
                    'isOwner' => $membership->organization->owner_membership_id === $membership->id,
                    'isCurrent' => $membership->organization_id === $currentId,
                ])
                ->values()
                ->all(),
        ]);
    }
}
