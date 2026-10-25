<?php

use App\Http\Controllers\Api\MeController;
use Illuminate\Support\Facades\Route;
use Miles\PlatformClient\PlatformConfig;

/*
|--------------------------------------------------------------------------
| Desktop app API
|--------------------------------------------------------------------------
|
| Served at {workspace}/restaurant/api/v1 for restaurant-electron-app.
| "platform.api" resolves the workspace and the member from the app's
| platform access token (no session, no CSRF); "branch.api" the branch from
| X-Branch-Id. The app starts with POST {workspace}/restaurant/api/session
| (platform-client), then GET me. Every web page is here too, as data, at
| api/v1/app/<web path> (App\Support\AppPages): same controllers, same
| permissions.
|
*/

if (! app(PlatformConfig::class)->configured()) {
    return;
}

Route::prefix(app(PlatformConfig::class)->path().'/api/v1')
    ->middleware(['api', 'platform.api', 'throttle:240,1'])
    ->name('api.')
    ->group(function () {
        Route::get('me', MeController::class)->name('me');
    });
