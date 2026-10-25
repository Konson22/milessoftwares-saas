<?php

use App\Models\User;
use App\Modules\Memberships\Models\Invitation;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Structure\Models\Branch;
use App\Modules\Structure\Models\Position;
use App\Support\Hosts\PlatformHosts;
use App\Support\Tenancy\TenantContext;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\Support\AuthorizationFixtures;

/*
| Release gate for tenant isolation (Phase 10). Instead of one test per page,
| these sweep every registered route, so a route added later without its
| guards, or one that binds a record without the tenant scope, fails here.
*/

uses(AuthorizationFixtures::class);

/**
 * @return list<RoutingRoute>
 */
function routesOnHost(string $domain): array
{
    return array_values(array_filter(
        Route::getRoutes()->getRoutes(),
        fn (RoutingRoute $route) => $route->getDomain() === $domain,
    ));
}

function middlewareOf(RoutingRoute $route): array
{
    return $route->gatherMiddleware();
}

test('every workspace route resolves the tenant, and all but public pages require a member', function () {
    $routes = routesOnHost('{tenant}.'.PlatformHosts::root());
    expect($routes)->not->toBeEmpty();

    $public = ['robots.txt', 'auth/login', 'auth/callback'];

    foreach ($routes as $route) {
        $middleware = middlewareOf($route);
        expect($middleware)->toContain('tenant');

        // Everything else needs a signed-in member of this organization.

        if (in_array($route->uri(), $public, true)) {
            continue;
        }

        expect($middleware)->toContain('auth');

        if ($route->uri() !== 'logout') {
            expect(in_array('workspace.access', $middleware, true))->toBeTrue("{$route->uri()} lacks workspace.access");
        }
    }
});

test('every admin route requires active platform staff', function () {
    foreach (routesOnHost(PlatformHosts::admin()) as $route) {
        // Sign-in pages, and staff invitations (guarded by the emailed,
        // single-use token instead: the invitee is not staff yet).
        if (in_array($route->uri(), ['robots.txt', 'login', 'two-factor', 'auth/login', 'logout', 'invitation/{token}', 'invitation/{token}/two-factor'], true)) {
            continue;
        }

        expect(middlewareOf($route))->toContain('auth')
            ->toContain('platform.staff')
            ->toContain('admin.idle');
    }
});

test('every product API route requires a client token with a scope, or a person\'s app token', function () {
    $api = array_filter(routesOnHost(PlatformHosts::auth()), fn (RoutingRoute $route) => str_starts_with($route->uri(), 'api/'));
    expect($api)->not->toBeEmpty();

    foreach ($api as $route) {
        // A person in an application's own app: api/v1/account only.
        if (in_array('api.user', middlewareOf($route), true)) {
            expect($route->uri())->toStartWith('api/v1/account/');

            continue;
        }

        $scoped = array_filter(middlewareOf($route), fn (string $m) => str_starts_with($m, 'api.client:'));
        expect($scoped)->not->toBeEmpty("{$route->uri()} has no api.client scope");
    }
});

test('no workspace route accepts another organization\'s record ids', function () {
    $this->prepareAuthorizationFixtures();
    $victim = $this->organization;

    // Records that really exist, in the victim organization.
    $member = $this->member();
    $records = [
        'branch' => $this->juba->id,
        'department' => $this->jubaSales->id,
        'membership' => $member->id,
        'assignment' => $this->grant($member, 'retail', 'auditor')->id,
        'position' => Position::query()->create(['title' => 'Cashier', 'code' => 'CSH'])->id,
        'invitation' => tap(new Invitation, fn (Invitation $i) => $i->forceFill([
            'email' => 'target@example.test', 'token_hash' => hash('sha256', 'sweep'),
            'invited_by_membership_id' => $this->owner->id, 'status' => 'pending', 'expires_at' => now()->addDay(),
        ])->save())->id,
    ];
    app(TenantContext::class)->clear();

    // The attacker owns a different organization: full rights on their own host.
    $attacker = Organization::factory()->withSlug('attacker-co')->create();
    $attackerOwner = OrganizationMembership::factory()->create(['organization_id' => $attacker->id]);
    $attacker->forceFill(['owner_membership_id' => $attackerOwner->id])->save();
    /** @var User $user */
    $user = $attackerOwner->user;
    $this->actingAs($user);

    $swept = 0;
    foreach (routesOnHost('{tenant}.'.PlatformHosts::root()) as $route) {
        $parameters = array_values(array_diff($route->parameterNames(), ['tenant']));
        if ($parameters === []) {
            continue;
        }

        $path = $route->uri();
        foreach ($parameters as $name) {
            expect(array_key_exists($name, $records))->toBeTrue("Add a foreign {$name} record to this sweep for {$route->uri()}");
            $path = str_replace('{'.$name.'}', $records[$name], $path);
        }

        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $response = $this->call($method, PlatformHosts::url('attacker-co.'.PlatformHosts::root(), $path), [
                'name' => 'Hijacked', 'code' => 'HJK', 'title' => 'Hijacked', 'status' => 'suspended',
            ]);

            expect($response->status())->toBe(404, "{$method} {$route->uri()} answered {$response->status()} for a foreign id");
            $swept++;
        }
    }

    expect($swept)->toBeGreaterThanOrEqual(8);

    // Nothing in the victim organization changed.
    app(TenantContext::class)->run($victim, function () use ($records) {
        expect(Branch::query()->find($records['branch'])->name)->toBe('Juba')
            ->and(OrganizationMembership::query()->find($records['membership'])->status->value)->toBe('active')
            ->and(Invitation::query()->find($records['invitation'])->status)->toBe('pending');
    });
});
