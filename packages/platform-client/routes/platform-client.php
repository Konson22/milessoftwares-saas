<?php

use Illuminate\Support\Facades\Route;
use Miles\PlatformClient\Http\Controllers\ProvisioningController;
use Miles\PlatformClient\Http\Controllers\SignInController;
use Miles\PlatformClient\Http\Controllers\WebhookController;

// Browser sign-in on the workspace host, under the product's path.
Route::middleware(['web', 'platform.tenant'])->group(function () {
    Route::get('auth/login', [SignInController::class, 'login'])->name('platform.login');
    Route::get('auth/callback', [SignInController::class, 'callback'])->name('platform.callback');
    Route::post('logout', [SignInController::class, 'logout'])->middleware('auth')->name('platform.logout');
});

// Server-to-server from the platform: signed, no session, no CSRF. Register
// these URLs in admin → Applications → Integration (webhook endpoint, and
// provisioning base URL .../platform).
Route::post('platform/webhooks', WebhookController::class)
    ->middleware('throttle:600,1')
    ->name('platform.webhooks');
Route::post('platform/tenants', ProvisioningController::class)
    ->middleware('throttle:120,1')
    ->name('platform.provisioning');
