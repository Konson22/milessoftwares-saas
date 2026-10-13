<?php

use App\Http\Controllers\EventController;
use Illuminate\Support\Facades\Route;
use Miles\PlatformClient\PlatformConfig;

/*
|--------------------------------------------------------------------------
| Business events from products
|--------------------------------------------------------------------------
|
| POST {workspace}/finance/events, server to server: no session, no CSRF.
| The caller proves itself with a platform-signed event token, bound to
| one organization and one source product (docs/shared-modules.md).
|
*/

if (! app(PlatformConfig::class)->configured()) {
    return;
}

Route::prefix(app(PlatformConfig::class)->path())
    ->post('events', EventController::class)
    ->name('events.store');
