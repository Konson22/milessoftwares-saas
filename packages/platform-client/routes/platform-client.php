<?php

use Illuminate\Support\Facades\Route;
use Miles\PlatformClient\Http\Controllers\ProvisioningController;
use Miles\PlatformClient\Http\Controllers\SignInController;
use Miles\PlatformClient\Http\Controllers\WebhookController;
use Miles\PlatformClient\PlatformConfig;

// Everything under the product's path: {slug}.<root>/<path>/...
Route::prefix(app(PlatformConfig::class)->path())->group(function () {
    // Browser sign-in on the workspace host. The callback is the tenant
    // callback path registered on the platform: /<path>/auth/callback.
    Route::middleware(['web', 'platform.tenant'])->group(function () {
        Route::get('auth/login', [SignInController::class, 'login'])->name('platform.login');
        Route::get('auth/callback', [SignInController::class, 'callback'])->name('platform.callback');
        Route::post('logout', [SignInController::class, 'logout'])->middleware('auth')->name('platform.logout');
    });

    // Server-to-server from the platform: signed, no session, no CSRF, any
    // host. Register in admin → Applications → Integration: webhook endpoint
    // .../<path>/platform/webhooks, provisioning base URL .../<path>/platform.
    Route::post('platform/webhooks', WebhookController::class)
        ->middleware('throttle:600,1')
        ->name('platform.webhooks');
    Route::post('platform/tenants', ProvisioningController::class)
        ->middleware('throttle:120,1')
        ->name('platform.provisioning');
});
