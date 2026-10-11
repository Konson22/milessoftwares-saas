<?php

use App\Modules\Applications\Catalog\CatalogImporter;
use App\Modules\Applications\Models\Application;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\Support\AdminFixtures;

uses(AdminFixtures::class);

beforeEach(function () {
    config(['platform.admin.idle_minutes' => 100_000]);

    app(CatalogImporter::class)->import(json_decode((string) file_get_contents(base_path('tests/Fixtures/catalogs/retail.json')), true));
    $this->retail = Application::query()->where('code', 'retail')->sole();
    $this->catalogAdmin = $this->staffWith(['catalog_admin']);
});

function updateRetail(string $prefix)
{
    return test()->actingAs(test()->catalogAdmin->user)->put(test()->adminUrl('/applications/'.test()->retail->id), [
        'name' => 'Retail POS',
        'status' => 'beta',
        'route_prefix' => $prefix,
        'sort_order' => 10,
    ]);
}

it('accepts a product path on workspace hosts', function () {
    updateRetail('retail')->assertSessionHasNoErrors();

    expect($this->retail->refresh()->route_prefix)->toBe('retail');
});

it('refuses a product path the workspace shell already serves', function (string $prefix) {
    updateRetail($prefix)->assertSessionHasErrors('route_prefix');

    expect($this->retail->refresh()->route_prefix)->not->toBe($prefix);
})->with(['members', 'manage', 'auth', 'logout', 'build', 'up']);

it('reserves every path the platform serves on workspace hosts', function () {
    $reserved = config('platform.workspace_paths');
    $root = config('platform.root_domain');

    // Routes reachable on a workspace host: the {tenant} group and routes
    // without a domain (health check).
    $segments = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route) => $route->getDomain() === null || $route->getDomain() === '{tenant}.'.$root)
        ->map(fn (RoutingRoute $route) => explode('/', trim($route->uri(), '/'))[0])
        ->filter(fn (string $segment) => $segment !== '' && preg_match('/^[a-z][a-z0-9-]*$/', $segment) === 1);

    // Files and folders under public/ are served on every host.
    $public = collect(scandir(public_path()) ?: [])
        ->filter(fn (string $entry) => preg_match('/^[a-z][a-z0-9-]*$/', $entry) === 1);

    $missing = $segments->merge($public)->unique()->diff($reserved)->values()->all();

    expect($missing)->toBe([], 'Add these to platform.workspace_paths: '.implode(', ', $missing));
});
