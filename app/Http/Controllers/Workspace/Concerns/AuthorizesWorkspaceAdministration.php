<?php

namespace App\Http\Controllers\Workspace\Concerns;

use App\Modules\Authorization\Support\AccessResolver;
use App\Modules\Authorization\Support\WorkspaceAbilities;
use App\Modules\Memberships\Models\OrganizationMembership;
use Illuminate\Http\Request;

trait AuthorizesWorkspaceAdministration
{
    protected function membership(Request $request): OrganizationMembership
    {
        $membership = $request->attributes->get('membership');
        abort_unless($membership instanceof OrganizationMembership, 403);

        return $membership;
    }

    protected function authorizeStructure(Request $request, AccessResolver $access): OrganizationMembership
    {
        $membership = $this->membership($request);
        abort_unless((new WorkspaceAbilities($access))->manageStructure($membership), 403);

        return $membership;
    }

    protected function authorizeAccessManagement(Request $request, AccessResolver $access): OrganizationMembership
    {
        $membership = $this->membership($request);
        abort_unless((new WorkspaceAbilities($access))->manageAccess($membership), 403);

        return $membership;
    }
}
