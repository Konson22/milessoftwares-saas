<?php

namespace App\Http\Middleware;

use App\Models\Station;
use App\Petrol\Currencies;
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
        $branch = fn (): ?Branch => app(CurrentBranch::class)->get()
            ?? (app(CurrentAccess::class)->get() === null ? null : app(Branches::class)->current($request));

        $mainIds = null;
        $mains = function () use (&$mainIds): array {
            if ($mainIds === null) {
                $mainIds = app(CurrentTenant::class)->has()
                    ? Station::query()->where('is_main', true)->pluck('branch_id')->all()
                    : [];
            }

            return $mainIds;
        };

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
                    'url' => $request->getSchemeAndHttpHost().'/',
                ];
            },
            'user' => fn () => ($user = $request->user()) === null ? null : [
                'name' => $user->getAttribute('name'),
                'email' => $user->getAttribute('email'),
            ],
            'access' => function () {
                $access = app(CurrentAccess::class)->get();

                return $access === null ? null : [
                    'isOwner' => $access->isOwner,
                    'permissions' => collect($access->roles)->pluck('permissions')->flatten()->unique()->values()->all(),
                ];
            },
            'currency' => fn () => app(CurrentTenant::class)->has() ? Money::currency() : null,
            'acceptedCurrencies' => function () use ($branch) {
                if (! app(CurrentTenant::class)->has() || ($current = $branch()) === null) {
                    return [];
                }

                return app(Currencies::class)->accepted($current->id);
            },
            'branch' => fn () => ($current = $branch()) === null ? null : [
                'id' => $current->id,
                'name' => $current->name,
                'main' => in_array($current->id, $mains(), true),
            ],
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
            'branches' => fn () => app(CurrentAccess::class)->get() === null ? [] : array_map(
                fn (Branch $branch) => [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'main' => in_array($branch->id, $mains(), true),
                ],
                app(Branches::class)->available(),
            ),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                // A sign-in just made for an attendant, shown once (AttendantController).
                'attendantSignIn' => fn () => $request->session()->get('attendantSignIn'),
            ],
        ];
    }
}
