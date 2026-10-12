<?php

use App\Http\Controllers\BranchController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\MedicineController;
use App\Http\Controllers\StockController;
use Illuminate\Support\Facades\Route;
use Miles\PlatformClient\PlatformConfig;

/*
|--------------------------------------------------------------------------
| Pharmacy
|--------------------------------------------------------------------------
|
| Served at {workspace}/pharmacy: every route here (and every platform-client
| route) carries the path prefix. "platform" resolves the workspace from the
| host and requires a member whose platform access allows the pharmacy;
| "branch" requires a chosen branch for pages that work on one branch.
|
*/

// Not connected yet (fresh checkout, no .env): boot without product routes.
if (! app(PlatformConfig::class)->configured()) {
    return;
}

Route::prefix(app(PlatformConfig::class)->path())
    ->middleware(['web', 'platform'])
    ->group(function () {
        Route::get('branch', [BranchController::class, 'edit'])->name('branch.edit');
        Route::put('branch', [BranchController::class, 'update'])->name('branch.update');

        // The catalogue is the organization's, not a branch's.
        Route::get('medicines', [MedicineController::class, 'index'])->can('pharmacy.medicines.view')->name('medicines.index');
        Route::middleware('can:pharmacy.medicines.manage')->group(function () {
            Route::get('medicines/create', [MedicineController::class, 'create'])->name('medicines.create');
            Route::post('medicines', [MedicineController::class, 'store'])->name('medicines.store');
            Route::get('medicines/{medicine}/edit', [MedicineController::class, 'edit'])->whereUuid('medicine')->name('medicines.edit');
            Route::put('medicines/{medicine}', [MedicineController::class, 'update'])->whereUuid('medicine')->name('medicines.update');
        });

        Route::middleware('branch')->group(function () {
            Route::get('/', HomeController::class)->name('home');

            // Stock of the branch being worked in.
            Route::middleware('branch.can:pharmacy.stock.view')->group(function () {
                Route::get('stock', [StockController::class, 'index'])->name('stock.index');
                Route::get('stock/{medicine}', [StockController::class, 'show'])->whereUuid('medicine')->name('stock.show');
            });
            Route::middleware('branch.can:pharmacy.stock.adjust')->group(function () {
                Route::post('stock/{medicine}/opening', [StockController::class, 'storeOpening'])->whereUuid('medicine')->name('stock.opening');
                Route::post('batches/{batch}/adjustments', [StockController::class, 'storeAdjustment'])->whereUuid('batch')->name('stock.adjust');
            });
        });
    });
