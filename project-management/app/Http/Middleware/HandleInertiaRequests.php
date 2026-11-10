<?php

namespace App\Http\Middleware;

use App\Access\CurrentAccess;
use App\Models\FunderContact;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\Modules;
use App\Support\Money;
use App\Tenancy\CurrentOrganization;
use App\Tenancy\Hosts;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        // Closures run when the page is rendered, after EnterWorkspace
        // has bound the organization and the member's access.
        return [
            ...parent::share($request),
            'product' => [
                'name' => config('app.name'),
                // The Miles Softwares website.
                'url' => Hosts::main(),
            ],
            'organization' => function () {
                $organization = app(CurrentOrganization::class)->get();

                return $organization === null ? null : [
                    'slug' => $organization->slug,
                    'name' => $organization->name,
                    'url' => Hosts::organization($organization),
                    // The workspace on the platform: its home, and its members and roles.
                    'workspaceUrl' => Hosts::organization($organization, '/'),
                    'membersUrl' => Hosts::organization($organization, '/members'),
                    'modules' => array_values(array_filter(Modules::keys(), $organization->hasModule(...))),
                    // It funds others: the funding sections show in its menu.
                    'funds' => $organization->isDonor(),
                ];
            },
            // Other workspaces the user has opened Projects in: the switcher
            // (a full page load: another workspace is another host, and the
            // platform signs them in there).
            'organizations' => fn () => ($user = $request->user()) instanceof User
                ? Organization::query()->where('status', Organization::ACTIVE)
                    ->whereHas('memberships', fn ($query) => $query->where('user_id', $user->id)->where('status', OrganizationMembership::ACTIVE))
                    ->orderBy('name')->get()->map(fn (Organization $organization) => ['slug' => $organization->slug, 'name' => $organization->name, 'url' => Hosts::organization($organization)])->values()->all()
                : [],
            // The organization currency: amounts are sent in its minor unit.
            'currency' => fn () => app(CurrentOrganization::class)->has() ? Money::currency() : null,
            'user' => fn () => ($user = $request->user()) instanceof User ? [
                'name' => $user->name,
                'email' => $user->email,
            ] : null,
            // The donor portal, for someone who acts for a funder here.
            'donorPortal' => function () use ($request) {
                if (! app(CurrentOrganization::class)->has() || FunderContact::forUser($request->user()) === null) {
                    return null;
                }

                return ['confined' => FunderContact::isConfined($request->user())];
            },
            // For showing and hiding UI only: every action is authorized
            // again on the server.
            'access' => function () {
                $access = app(CurrentAccess::class)->get();

                return $access === null ? null : [
                    'isOwner' => $access->isOwner,
                    'permissions' => $access->all(),
                ];
            },
            // The bell. Inside an organization only: each organization's are its own.
            'unreadNotifications' => fn () => app(CurrentOrganization::class)->has() && ($user = $request->user()) instanceof User
                ? UserNotification::query()->where('user_id', $user->id)->whereNull('read_at')->count()
                : 0,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'warning' => fn () => $request->session()->get('warning'),
            ],
        ];
    }
}
