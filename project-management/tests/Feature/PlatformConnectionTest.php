<?php

use App\Access\Catalog;
use Illuminate\Support\Facades\Http;

/*
| Projects is one of the platform's products: people sign in through the
| platform, open it in their workspace, and hold the permissions the
| catalog declares. Working in it calls the platform only for that.
*/

it('signs in through the platform, inside the workspace', function () {
    $names = collect(app('router')->getRoutes()->getRoutesByName())->keys();

    expect($names)->toContain('platform.login', 'platform.callback', 'platform.logout')
        ->and($names->filter(fn (string $name) => in_array($name, ['login', 'register', 'password.request', 'two-factor.login'], true))->all())->toBe([]);

    $this->get($this->organizationUrl('acme', 'projects'))->assertRedirect(route('platform.login'));
});

it('declares its permissions and roles in the catalog', function () {
    $catalog = json_decode((string) file_get_contents(base_path('platform/catalog.json')), true);
    $keys = collect($catalog['permissions'])->pluck('key');

    expect($catalog['application']['code'] ?? $catalog['application'] ?? null)->not->toBeNull()
        ->and($keys->duplicates()->all())->toBe([])
        ->and($keys->every(fn (string $key) => preg_match('/^[a-z][a-z0-9_.]*$/', $key) === 1))->toBeTrue()
        ->and(Catalog::rolePermissions('funder_contact'))->toBe(['projects.donor.view'])
        ->and(Catalog::permissions())->toContain('organization.manage', 'projects.view');

    foreach ($catalog['roles'] as $role) {
        expect(array_diff($role['permissions'], $keys->all()))->toBe([], "Role {$role['key']} names an unknown permission.");
    }
});

it('works in a workspace without calling anything but the platform', function () {
    $this->actingAsMember('acme', [], owner: true);

    $this->post($this->organizationUrl('acme', 'projects'), ['code' => 'SOLO', 'name' => 'Solo'])->assertSessionHasNoErrors();
    $this->get($this->organizationUrl('acme', 'projects'))->assertOk();

    Http::assertNotSent(fn ($request) => ! str_starts_with($request->url(), (string) config('platform-client.backchannel_url', 'http://')));
});
