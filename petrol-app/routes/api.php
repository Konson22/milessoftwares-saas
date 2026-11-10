<?php

use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\OutboxController;
use Illuminate\Support\Facades\Route;
use Miles\PlatformClient\PlatformConfig;

/*
| Device API at {workspace}/petrol/api/v1. platform.api authenticates the
| access token. branch.api reads X-Branch-Id. Submissions are applied once.
| The mobile app starts with POST {workspace}/petrol/api/session
| (platform-client), then GET me. Every web page is here too, as data, at
| api/v1/app/<web path> (App\Support\AppPages): same controllers, same
| permissions.
*/

if (! app(PlatformConfig::class)->configured()) {
    return;
}

Route::prefix(app(PlatformConfig::class)->path().'/api/v1')
    ->middleware(['api', 'platform.api', 'throttle:240,1'])
    ->name('api.')
    ->group(function () {
        Route::get('me', MeController::class)->name('me');

        Route::middleware('branch.api')->group(function () {
            Route::post('outbox', [OutboxController::class, 'store'])->name('outbox.store');
            Route::get('outbox/kit', [OutboxController::class, 'kit'])->name('outbox.kit');
        });
    });
