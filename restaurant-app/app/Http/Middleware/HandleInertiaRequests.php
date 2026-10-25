<?php

namespace App\Http\Middleware;

use App\Support\Branches;
use App\Support\CurrentBranch;
use App\Support\Money;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Miles\PlatformClient\Access\CurrentAccess;
use Miles\PlatformClient\Api\Branch;
use Miles\PlatformClient\Tenancy\CurrentTenant;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        // The branch being worked in: set on branch pages, and the one chosen
        // earlier on the others (the menu), so the sections stay the same.
        $branch = fn (): ?Branch => app(CurrentBranch::class)->get()
            ?? (app(CurrentAccess::class)->get() === null ? null : app(Branches::class)->current($request));

        // Closures run when the page is rendered — after platform.tenant /
        // platform.access have bound the workspace and access for this request.
        return [
            ...parent::share($request),
            'product' => [
                'name' => config('app.name'),
                'code' => config('platform-client.application'),
            ],
            'workspace' => function () use ($request) {
                $tenant = app(CurrentTenant::class)->get();

                return $tenant === null ? null : [
                    'slug' => $tenant->slug,
                    'name' => $tenant->displayName,
                    // The workspace home on the platform (outside this product).
                    'url' => $request->getSchemeAndHttpHost().'/',
                ];
            },
            'user' => fn () => ($user = $request->user()) === null ? null : [
                'name' => $user->getAttribute('name'),
                'email' => $user->getAttribute('email'),
            ],
            // For showing and hiding UI only: every action is authorized
            // again on the server.
            'access' => function () {
                $access = app(CurrentAccess::class)->get();

                return $access === null ? null : [
                    'isOwner' => $access->isOwner,
                    'permissions' => collect($access->roles)->pluck('permissions')->flatten()->unique()->values()->all(),
                ];
            },
            // The workspace currency: amounts are sent in its minor unit.
            'currency' => fn () => app(CurrentTenant::class)->has() ? Money::currency() : null,
            // The branch this page works in, for the header and the sections.
            'branch' => fn () => ($current = $branch()) === null ? null : ['id' => $current->id, 'name' => $current->name],
            // Permissions that apply in that branch, for showing and hiding UI.
            'branchPermissions' => function () use ($branch) {
                $current = $branch();
                $access = app(CurrentAccess::class)->get();

                if ($current === null || $access === null) {
                    return [];
                }

                return collect($access->roles)->pluck('permissions')->flatten()->unique()
                    ->filter(fn (string $permission) => $access->allowsIn($permission, branchId: $current->id))
                    ->values()->all();
            },
            // The branches open to the member, for the branch switcher.
            'branches' => fn () => app(CurrentAccess::class)->get() === null ? [] : array_map(
                fn (Branch $branch) => ['id' => $branch->id, 'name' => $branch->name],
                app(Branches::class)->available(),
            ),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
            ],
        ];
    }
}
