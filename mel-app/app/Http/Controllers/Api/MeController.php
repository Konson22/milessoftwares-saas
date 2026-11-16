<?php

namespace App\Http\Controllers\Api;

use App\Mel\Access;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Miles\PlatformClient\Tenancy\CurrentTenant;

/**
 * Who the field app is signed in as, in which workspace, and whether they
 * may collect (GET {workspace}/mel/api/v1/me).
 */
class MeController
{
    public function __invoke(Request $request, Access $access, CurrentTenant $tenant): JsonResponse
    {
        $user = $request->user();
        assert($user instanceof User);
        $organization = $tenant->require();

        return response()->json([
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'organization' => ['id' => $organization->id, 'name' => $organization->displayName, 'slug' => $organization->slug],
            'canCollect' => $access->can('mel.collect.submit'),
        ]);
    }
}
