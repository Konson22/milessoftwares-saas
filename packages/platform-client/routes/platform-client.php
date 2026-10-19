<?php

use Illuminate\Support\Facades\Route;
use Miles\PlatformClient\Http\Controllers\ProvisioningController;
use Miles\PlatformClient\Http\Controllers\SignInController;
use Miles\PlatformClient\Http\Controllers\WebhookController;
use Miles\PlatformClient\PlatformConfig;

$config = app(PlatformConfig::class);

// Once per application served here, under its path: {slug}.<root>/<path>/...
// Names: platform.login for the primary, platform.<code>.login for a hosted one.
foreach ($config->applications() as $application) {
    $config->runAs($application, fn () => Route::prefix($config->path())
        ->middleware("platform.app:{$application}")
        ->name($config->routeName(''))
        ->group(function () {
            // Browser sign-in on the workspace host. The callback is the tenant
            // callback path registered on the platform: /<path>/auth/callback.
            Route::middleware(['web', 'platform.tenant'])->group(function () {
                Route::get('auth/login', [SignInController::class, 'login'])->name('login');
                Route::get('auth/callback', [SignInController::class, 'callback'])->name('callback');
                Route::post('logout', [SignInController::class, 'logout'])->middleware('auth')->name('logout');
            });

            // Server-to-server from the platform: signed, no session, no CSRF, any
            // host. Register in admin → Applications → Integration: webhook endpoint
            // .../<path>/platform/webhooks, provisioning base URL .../<path>/platform.
            Route::post('platform/webhooks', WebhookController::class)
                ->middleware('throttle:600,1')
                ->name('webhooks');
            Route::post('platform/tenants', ProvisioningController::class)
                ->middleware('throttle:120,1')
                ->name('provisioning');
        }));
}
