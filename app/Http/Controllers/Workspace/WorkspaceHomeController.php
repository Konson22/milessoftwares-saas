<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceHomeController extends Controller
{
    public function __invoke(TenantContext $tenant): Response
    {
        $organization = $tenant->require();

        return Inertia::render('workspace/home', [
            'organization' => [
                'id' => $organization->id,
                'displayName' => $organization->display_name,
            ],
            // Entitled, authorized modules arrive with Phases 4–6.
            'modules' => [],
        ]);
    }
}
