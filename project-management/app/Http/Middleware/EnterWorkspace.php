<?php

namespace App\Http\Middleware;

use App\Access\Access;
use App\Access\Catalog;
use App\Access\CurrentAccess;
use App\Actions\Beneficiaries;
use App\Actions\Organizations;
use App\Models\User;
use App\Tenancy\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Miles\PlatformClient\Access\CurrentAccess as PlatformAccess;
use Miles\PlatformClient\Tenancy\CurrentTenant;
use Symfony\Component\HttpFoundation\Response;

/**
 * After the platform middleware ("platform": the organization from the
 * workspace host, a signed-in member allowed into Projects): the
 * organization and the member's access as this app knows them.
 *
 * The platform's organization becomes (or refreshes) the local one with the
 * same id, the member's membership keeps a copy of their access, and both
 * are bound for the request (App\Tenancy\CurrentOrganization,
 * App\Access\CurrentAccess), so the rest of the app works as it did.
 *
 * Runs before route-model binding (bootstrap/app.php), so {project} and the
 * rest are looked up inside the organization.
 */
final class EnterWorkspace
{
    public function __construct(
        private readonly CurrentTenant $tenant,
        private readonly PlatformAccess $platformAccess,
        private readonly CurrentOrganization $organizations,
        private readonly CurrentAccess $access,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $platform = $this->platformAccess->get();
        abort_unless($user instanceof User && $platform !== null, 403);

        $organization = Organizations::mirror($this->tenant->require());
        abort_unless($organization->isActive(), 403, 'This organization is suspended.');

        $permissions = $platform->isOwner
            ? Catalog::permissions()
            : array_values(array_unique(array_merge(...array_map(fn (array $role) => $role['permissions'], $platform->roles ?: [['permissions' => []]]))));
        sort($permissions);
        $roles = $platform->roleKeys();
        sort($roles);

        $membership = Organizations::enter($organization, $user, new Access($organization->id, '', $platform->isOwner, $permissions, $roles));

        $this->organizations->set($organization);

        // A workspace a donor created for this organization: connected the
        // first time it opens Projects, by whoever claimed it.
        if ($platform->isOwner) {
            Beneficiaries::connectCreated($organization, $user);
        }
        $this->access->set(new Access($organization->id, $membership->id, $platform->isOwner, $permissions, $roles));

        return $next($request);
    }
}
