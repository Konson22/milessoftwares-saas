<?php

namespace App\Http\Middleware;

use App\Access\Access;
use App\Access\CurrentAccess;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Tenancy\CurrentOrganization;
use Closure;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Miles\PlatformClient\Api\NotVisible;
use Miles\PlatformClient\Api\PlatformApi;
use Miles\PlatformClient\Api\PlatformUnavailable;
use Miles\PlatformClient\Http\Middleware\RequireIntegrationToken;
use Miles\SharedModuleKit\EventToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * After platform.integration:mel (ADR-0019 §20): M&E reads Projects on
 * behalf of one member, and gets exactly what that member would see in
 * Projects, nothing more.
 *
 *   - The organization is the token's, signed by the platform; nothing the
 *     caller sends can name another one.
 *   - The member is the token's acting user (the platform only puts an
 *     active member there). Their access is asked of the platform now, in
 *     Projects' own catalog, as on every workspace request; they need
 *     projects.view, and team-only projects stay with their team
 *     (App\Access\ProjectVisibility, through the bound user).
 *   - Nothing is written: an organization that has never opened Projects is
 *     not created here.
 *
 *   403 acting_user_required | organization_inactive | forbidden
 *   404 not_provisioned | not_entitled      503 platform_unavailable
 */
final class EnterIntegration
{
    public function __construct(
        private readonly PlatformApi $platform,
        private readonly CurrentOrganization $organizations,
        private readonly CurrentAccess $access,
        private readonly Auth $auth,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var EventToken $token */
        $token = $request->attributes->get(RequireIntegrationToken::TOKEN);
        if ($token->actingUser === null) {
            return $this->refuse(403, 'acting_user_required');
        }

        $organization = Organization::query()->find($token->organizationId);
        if ($organization === null) {
            return $this->refuse(404, 'not_provisioned');
        }
        if (! $organization->isActive()) {
            return $this->refuse(403, 'organization_inactive');
        }

        try {
            $platform = $this->platform->effectiveAccess($organization->id, $token->actingUser);
        } catch (NotVisible) {
            return $this->refuse(404, 'not_entitled');
        } catch (PlatformUnavailable) {
            return $this->refuse(503, 'platform_unavailable');
        }
        if (! $platform->allowed) {
            return $this->refuse(403, 'forbidden');
        }

        $permissions = Access::permissionsFrom($platform);
        if (! in_array('projects.view', $permissions, true)) {
            return $this->refuse(403, 'forbidden');
        }
        $roles = $platform->roleKeys();
        sort($roles);

        // The member as Projects knows them, or (never signed in here) just
        // their id: enough for visibility, which goes by user id.
        $user = User::query()->find($token->actingUser) ?? (new User)->forceFill(['id' => $token->actingUser]);
        $membershipId = OrganizationMembership::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $token->actingUser)
            ->value('id');

        $this->organizations->set($organization);
        $this->access->set(new Access($organization->id, (string) $membershipId, $platform->isOwner, $permissions, $roles));
        $this->auth->guard()->setUser($user);

        return $next($request);
    }

    private function refuse(int $status, string $error): JsonResponse
    {
        return response()->json(['error' => $error], $status);
    }
}
