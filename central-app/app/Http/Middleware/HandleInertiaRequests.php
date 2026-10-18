<?php

namespace App\Http\Middleware;

use App\Modules\Administration\Enums\PlatformPermission;
use App\Modules\Administration\Models\PlatformStaff;
use App\Modules\Applications\Support\Launcher;
use App\Modules\Authorization\Support\WorkspaceAbilities;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Support\Hosts\HostType;
use App\Support\Hosts\PlatformHosts;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'platform' => $this->platformLinks($request),
            // Set on workspace hosts (and on app. once an organization is current).
            // Admin host only: the signed-in staff member and their permissions.
            'staff' => fn () => ($staff = $request->attributes->get('staff')) instanceof PlatformStaff
                ? ['permissions' => array_map(fn (PlatformPermission $permission) => $permission->value, $staff->permissions())]
                : null,
            // Workspace hosts only: which administration links to show.
            'abilities' => fn () => ($membership = $request->attributes->get('membership')) instanceof OrganizationMembership
                ? app(WorkspaceAbilities::class)->for($membership)
                : null,
            // Workspace hosts only: the applications the member can open, for
            // the sidebar (same rules as the launcher on the workspace home).
            'apps' => fn () => ($membership = $request->attributes->get('membership')) instanceof OrganizationMembership
                && PlatformHosts::classify($request->getHost())['type'] === HostType::Workspace
                ? app(Launcher::class)->for($membership)
                : [],
            'tenant' => fn () => ($organization = app(TenantContext::class)->organization()) === null ? null : [
                'displayName' => $organization->display_name,
                // The app. sidebar shows organization settings and billing to the owner only.
                'isOwner' => ($membership = $request->attributes->get('membership')) instanceof OrganizationMembership
                    && $organization->owner_membership_id === $membership->id,
            ],
        ];
    }

    /**
     * Account links differ per host: only the auth host serves account
     * settings and the central sign-out; app. and admin. sign out locally.
     *
     * @return array{host: string, isAuthHost: bool, accountUrl: string, securityUrl: string, logoutUrl: string}
     */
    private function platformLinks(Request $request): array
    {
        $type = PlatformHosts::classify($request->getHost())['type'];

        return [
            'host' => $type->value,
            'isAuthHost' => $type === HostType::Auth,
            'accountUrl' => PlatformHosts::url(PlatformHosts::auth(), 'settings/profile'),
            'securityUrl' => PlatformHosts::url(PlatformHosts::auth(), 'settings/security'),
            'logoutUrl' => match ($type) {
                HostType::App => PlatformHosts::url(PlatformHosts::app(), 'logout'),
                HostType::Admin => PlatformHosts::url(PlatformHosts::admin(), 'logout'),
                HostType::Workspace => PlatformHosts::url($request->getHost(), 'logout'),
                default => PlatformHosts::url(PlatformHosts::auth(), 'logout'),
            },
        ];
    }
}
