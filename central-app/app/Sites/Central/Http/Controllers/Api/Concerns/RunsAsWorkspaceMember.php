<?php

namespace App\Sites\Central\Http\Controllers\Api\Concerns;

use App\Models\User;
use App\Modules\Applications\Models\Application;
use App\Modules\Authorization\Support\AccessResolver;
use App\Modules\Authorization\Support\WorkspaceAbilities;
use App\Modules\Memberships\Enums\MembershipStatus;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Organizations\Enums\OrganizationStatus;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationSlug;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * An application's own app acting in a workspace, as the person signed in:
 * the workspace by its address, the person's active membership there, and
 * what they may administer.
 */
trait RunsAsWorkspaceMember
{
    /**
     * Runs as the person's active membership in the workspace, when `$allowed`
     * says they may. Anyone else learns nothing about it (404).
     *
     * @param  callable(WorkspaceAbilities, OrganizationMembership): bool  $allowed
     * @param  callable(OrganizationMembership, Application, Organization): JsonResponse  $callback
     */
    private function asMember(Request $request, string $slug, callable $allowed, callable $callback): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var Application $application */
        $application = $request->attributes->get('api_application');

        $organization = OrganizationSlug::query()
            ->with('organization')
            ->where('slug', strtolower($slug))
            ->where('is_primary', true)
            ->first()
            ?->organization;

        abort_unless($organization instanceof Organization && $organization->status->resolvesWorkspace(), 404);

        return app(TenantContext::class)->run($organization, function () use ($organization, $user, $application, $allowed, $callback): JsonResponse {
            $membership = OrganizationMembership::query()
                ->where('user_id', $user->getKey())
                ->where('status', MembershipStatus::Active)
                ->first();

            abort_unless($membership !== null, 404);
            // Status is only disclosed to members.
            if ($organization->status === OrganizationStatus::Suspended) {
                return response()->json(['error' => 'organization_suspended', 'message' => __('This business is suspended.')], 403);
            }
            abort_unless($allowed(new WorkspaceAbilities(app(AccessResolver::class)), $membership), 403);

            return $callback($membership, $application, $organization);
        });
    }
}
