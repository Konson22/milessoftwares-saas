<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Modules\Applications\Support\Launcher;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceHomeController extends Controller
{
    public function __invoke(Request $request, TenantContext $tenant, Launcher $launcher): Response
    {
        $organization = $tenant->require();
        $membership = $request->attributes->get('membership');
        abort_unless($membership instanceof OrganizationMembership, 403);

        return Inertia::render('workspace/home', [
            'organization' => [
                'id' => $organization->id,
                'displayName' => $organization->display_name,
            ],
            'modules' => $launcher->for($membership),
        ]);
    }
}
