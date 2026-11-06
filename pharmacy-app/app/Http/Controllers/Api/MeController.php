<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Branches;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Miles\PlatformClient\Access\CurrentAccess;
use Miles\PlatformClient\Api\Branch;
use Miles\PlatformClient\Api\PlatformApi;
use Miles\PlatformClient\Tenancy\CurrentTenant;

/**
 * GET api/v1/me: who is signed in to the app, in which workspace, and the
 * branches they may work in (as the web's branch choice). With none, why:
 * the organization has no branch yet, or the member has no role in one.
 * ?fresh=1 asks the platform rather than the cache: the app has just added a
 * branch there, and the person is waiting to work in it.
 */
class MeController extends Controller
{
    public function __invoke(Request $request, Branches $branches, CurrentTenant $tenant, CurrentAccess $access, PlatformApi $api): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        if ($request->boolean('fresh')) {
            $api->forget($tenant->require()->id);
        }
        $available = $branches->available();
        $currency = Money::currency();

        return response()->json([
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'organization' => [
                'id' => $tenant->require()->id,
                'slug' => $tenant->require()->slug,
                'name' => Workspace::current()->display_name ?? $tenant->require()->displayName,
                'currency' => $currency,
                'decimals' => Money::decimals($currency),
            ],
            'is_owner' => $access->get()->isOwner ?? false,
            'branches' => array_map(fn (Branch $branch) => [
                'id' => $branch->id,
                'name' => $branch->name,
                'code' => $branch->code,
                'city' => $branch->city,
            ], $available),
            'reason' => $available !== [] ? null : ($branches->anyActive() ? 'no_role' : 'no_branches'),
        ]);
    }
}
