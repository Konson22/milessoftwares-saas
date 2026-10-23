<?php

use App\Http\Controllers\Api\CounterController;
use App\Http\Controllers\Api\ItemController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\PrescriptionController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\TillController;
use Illuminate\Support\Facades\Route;
use Miles\PlatformClient\PlatformConfig;

/*
|--------------------------------------------------------------------------
| Counter API (the mobile and desktop counter apps)
|--------------------------------------------------------------------------
|
| Served at {workspace}/pharmacy/api/v1, and for the Retail module at
| {workspace}/retail/api/v1 (names "retail.api.*", "platform.app:retail"
| first, so its own mobile client's tokens and retail.* permissions apply).
| "platform.api" resolves the workspace and the member from the app's
| platform access token (no session, no CSRF); "branch.api" the branch from
| X-Branch-Id. The app starts with POST {workspace}/<path>/api/session
| (platform-client). Same permissions as the web pages, checked in the
| branch. Prescriptions are the pharmacy's only.
|
*/

$config = app(PlatformConfig::class);
if (! $config->configured()) {
    return;
}

$counter = function (string $code): void {
    Route::get('me', MeController::class)->name('me');

    Route::middleware('branch.api')->group(function () use ($code) {
        Route::get('counter', CounterController::class)->name('counter');

        Route::middleware("branch.can:{$code}.sales.create")->group(function () {
            Route::get('items', [ItemController::class, 'index'])->name('items.index');
            Route::get('items/lookup', [ItemController::class, 'lookup'])->name('items.lookup');

            Route::get('till', [TillController::class, 'show'])->name('till.show');
            Route::post('till', [TillController::class, 'store'])->name('till.store');
            Route::post('till/movements', [TillController::class, 'move'])->name('till.movements.store');
            Route::post('till/close', [TillController::class, 'close'])->name('till.close');

            Route::post('sales', [SaleController::class, 'store'])->name('sales.store');
        });

        // Their own sales to sellers; the branch's with sales.view.
        Route::middleware("branch.can:{$code}.sales.view,{$code}.sales.create")->group(function () {
            Route::get('sales', [SaleController::class, 'index'])->name('sales.index');
            Route::get('sales/{sale}', [SaleController::class, 'show'])->whereUuid('sale')->name('sales.show');
            Route::post('sales/{sale}/printed', [SaleController::class, 'printed'])->whereUuid('sale')->name('sales.printed');
        });
        Route::middleware("branch.can:{$code}.sales.void")->group(function () {
            Route::post('sales/{sale}/void', [SaleController::class, 'void'])->whereUuid('sale')->name('sales.void');
            Route::post('sales/{sale}/returns', [SaleController::class, 'storeReturn'])->whereUuid('sale')->name('sales.returns.store');
        });
    });
};

Route::prefix($config->path().'/api/v1')
    ->middleware(['api', 'platform.api', 'throttle:240,1'])
    ->name('api.')
    ->group(function () use ($counter) {
        $counter('pharmacy');

        Route::middleware('branch.api')->group(function () {
            Route::middleware('branch.can:pharmacy.prescriptions.view')->group(function () {
                Route::get('prescriptions', [PrescriptionController::class, 'index'])->name('prescriptions.index');
                Route::get('prescriptions/{prescription}', [PrescriptionController::class, 'show'])->whereUuid('prescription')->name('prescriptions.show');
            });
            Route::post('prescriptions', [PrescriptionController::class, 'store'])
                ->middleware('branch.can:pharmacy.prescriptions.record')
                ->name('prescriptions.store');
            Route::middleware('branch.can:pharmacy.prescriptions.verify')->group(function () {
                Route::post('prescriptions/{prescription}/verify', [PrescriptionController::class, 'verify'])->whereUuid('prescription')->name('prescriptions.verify');
                Route::post('prescriptions/{prescription}/reject', [PrescriptionController::class, 'reject'])->whereUuid('prescription')->name('prescriptions.reject');
            });
            Route::post('prescriptions/{prescription}/dispense', [PrescriptionController::class, 'dispense'])
                ->whereUuid('prescription')
                ->middleware('branch.can:pharmacy.sales.create')
                ->name('prescriptions.dispense');
        });
    });

if (in_array('retail', $config->applications(), true)) {
    Route::prefix($config->runAs('retail', fn () => $config->path()).'/api/v1')
        ->middleware(['api', 'platform.app:retail', 'platform.api', 'throttle:240,1'])
        ->name('retail.api.')
        ->group(fn () => $counter('retail'));
}

// The web pages for the desktop app, under api/v1/app: registered after
// routes/web.php (bootstrap/app.php, App\Support\AppPages).
