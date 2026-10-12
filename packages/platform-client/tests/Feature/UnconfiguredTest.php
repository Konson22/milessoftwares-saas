<?php

use Illuminate\Support\Facades\Route;
use Miles\PlatformClient\PlatformClientServiceProvider;
use Miles\PlatformClient\PlatformConfig;

it('boots without configuration, registering no platform routes', function () {
    // A fresh checkout: composer install runs package:discover before .env exists.
    config(['platform-client.application' => null]);
    $routesBefore = count(Route::getRoutes()->getRoutes());

    (new PlatformClientServiceProvider(app()))->boot(app('router'), app('Illuminate\Contracts\Auth\Access\Gate'));

    expect(app(PlatformConfig::class)->configured())->toBeFalse()
        ->and(count(Route::getRoutes()->getRoutes()))->toBe($routesBefore);
});

it('still refuses to work without configuration', function () {
    config(['platform-client.application' => null]);

    expect(fn () => app(PlatformConfig::class)->path())->toThrow(RuntimeException::class, 'PLATFORM_APPLICATION');
});
