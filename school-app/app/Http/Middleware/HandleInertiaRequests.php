<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use App\Support\Money;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Miles\PlatformClient\Access\CurrentAccess;
use Miles\PlatformClient\Tenancy\CurrentTenant;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
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
            // What kind of school this is (its words and switches) and the
            // term it is in; null until the school is set up.
            'school' => function () {
                $workspace = app(CurrentTenant::class)->has() ? Workspace::current() : null;
                if ($workspace?->category === null) {
                    return null;
                }
                $term = $workspace->currentTerm()->with('academicYear')->first();

                return [
                    ...$workspace->category->toArray(),
                    'term' => $term === null ? null : ['id' => $term->id, 'title' => $term->title()],
                ];
            },
            // The workspace currency: amounts are sent in its minor unit.
            'currency' => fn () => app(CurrentTenant::class)->has() ? Money::currency() : null,
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
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
            ],
        ];
    }
}
