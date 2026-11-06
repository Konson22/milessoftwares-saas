<?php

namespace App\Sites\Central\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Applications\Models\Application;
use App\Modules\Entitlements\Actions\ManageEntitlements;
use App\Modules\Entitlements\Models\Entitlement;
use App\Modules\Marketing\Support\MarketingCatalog;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Memberships\Support\MembershipLookup;
use App\Modules\Organizations\Actions\AddFirstBranch;
use App\Modules\Organizations\Actions\CheckSlugAvailability;
use App\Modules\Organizations\Actions\CreateOrganization;
use App\Modules\Organizations\Models\Organization;
use App\Sites\Central\Http\Requests\Api\CreateAppOrganizationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A new business from an application's own app (the desktop counter): as
 * "New business" on the dashboard, with the free trial of the app's own
 * application when it offers one, and a first branch. The person becomes
 * its owner and signs in to the workspace next.
 */
class AppOrganizationController extends Controller
{
    /**
     * The person's businesses that use the app's application now (a plan or a
     * trial): after signing in without a workspace address, the app opens the
     * only one or lets them choose.
     */
    public function index(Request $request, MembershipLookup $memberships): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var Application $application */
        $application = $request->attributes->get('api_application');

        $mine = $memberships->activeFor($user);
        $entitled = Entitlement::acrossTenants()
            ->current()
            ->whereNull('feature_key')
            ->where('application_id', $application->id)
            ->whereIn('organization_id', $mine->pluck('organization_id'))
            ->pluck('organization_id')
            ->all();

        return response()->json([
            'data' => $mine
                ->filter(fn (OrganizationMembership $membership) => in_array($membership->organization_id, $entitled, true)
                    && $membership->organization->primarySlug !== null)
                ->map(fn (OrganizationMembership $membership) => [
                    'id' => $membership->organization_id,
                    'slug' => $membership->organization->primarySlug->slug,
                    'name' => $membership->organization->display_name,
                    'status' => $membership->organization->status->value,
                    'is_owner' => $membership->organization->owner_membership_id === $membership->id,
                ])
                ->values()
                ->all(),
        ]);
    }

    public function slugAvailability(Request $request, CheckSlugAvailability $slugs): JsonResponse
    {
        $errors = $slugs->errors((string) $request->query('slug', ''));

        return response()->json(['available' => $errors === [], 'errors' => $errors]);
    }

    public function store(
        CreateAppOrganizationRequest $request,
        CreateOrganization $create,
        AddFirstBranch $firstBranch,
        ManageEntitlements $entitlements,
        MarketingCatalog $catalog,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        /** @var Application $application */
        $application = $request->attributes->get('api_application');

        // The business and its branch together, or neither.
        $organization = DB::transaction(function () use ($create, $firstBranch, $user, $request): Organization {
            $organization = $create->handle($user, $request->validated());
            $firstBranch->handle($organization, $user, $request->branch()['name'], $request->branch()['city']);

            return $organization;
        });

        $trial = in_array($application->code, array_column($catalog->trialable(), 'code'), true)
            ? $entitlements->startTrial($organization, $application, $user)
            : null;

        return response()->json([
            'organization' => [
                'id' => $organization->id,
                'slug' => $organization->primarySlug?->slug,
                'name' => $organization->display_name,
            ],
            'trial' => $trial === null ? null : [
                'application' => $application->code,
                'ends_at' => $trial->valid_until?->toDateString(),
            ],
        ], 201);
    }
}
