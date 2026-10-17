<?php

namespace App\Sites\Central\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Modules\Applications\Support\Launcher;
use App\Modules\Authorization\Support\AccessResolver;
use App\Modules\Authorization\Support\WorkspaceAbilities;
use App\Modules\Identity\Http\Controllers\FirstPartySignInController;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administrators get the workspace home. Everyone else gets a page to pick
 * one of their applications, and right after signing in goes straight into
 * it when they have only one; coming back later shows the picker.
 */
class WorkspaceHomeController extends Controller
{
    public function __invoke(Request $request, TenantContext $tenant, Launcher $launcher, AccessResolver $access): Response|RedirectResponse
    {
        $organization = $tenant->require();
        $membership = $request->attributes->get('membership');
        abort_unless($membership instanceof OrganizationMembership, 403);

        $modules = $launcher->for($membership);
        $props = [
            'organization' => [
                'id' => $organization->id,
                'displayName' => $organization->display_name,
            ],
            'modules' => $modules,
        ];

        if ((new WorkspaceAbilities($access))->administers($membership)) {
            return Inertia::render('central/workspace/home', $props);
        }

        if (count($modules) === 1 && $request->session()->get(FirstPartySignInController::JUST_SIGNED_IN) === true) {
            return redirect()->away($modules[0]['href']);
        }

        return Inertia::render('central/workspace/apps', $props);
    }
}
