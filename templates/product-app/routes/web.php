<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\RecordController;
use Illuminate\Support\Facades\Route;
use Miles\PlatformClient\PlatformConfig;

/*
|--------------------------------------------------------------------------
| Product pages
|--------------------------------------------------------------------------
|
| Served at {workspace}/<path>: every route here (and every platform-client
| route) carries the path prefix. The proxy must not strip it. The
| "platform" middleware resolves the workspace from the host and requires a
| signed-in member whose platform access allows this product.
|
*/

// Not connected yet (fresh checkout, no .env): boot without product routes.
if (! app(PlatformConfig::class)->configured()) {
    return;
}

Route::prefix(app(PlatformConfig::class)->path())
    ->middleware(['web', 'platform'])
    ->group(function () {
        Route::get('/', HomeController::class)->name('home');

        Route::get('records', [RecordController::class, 'index'])
            ->can('blueprint.records.view')
            ->name('records.index');
        Route::post('records', [RecordController::class, 'store'])
            ->can('blueprint.records.manage')
            ->name('records.store');
    });
