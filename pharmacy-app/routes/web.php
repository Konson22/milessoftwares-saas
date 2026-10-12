<?php

use App\Http\Controllers\BranchController;
use App\Http\Controllers\GoodsReceiptController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\MedicineController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\SupplierController;
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

        // Suppliers are the organization's too.
        Route::get('suppliers', [SupplierController::class, 'index'])->can('pharmacy.purchasing.view')->name('suppliers.index');
        Route::middleware('can:pharmacy.purchasing.manage')->group(function () {
            Route::post('suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
            Route::put('suppliers/{supplier}', [SupplierController::class, 'update'])->whereUuid('supplier')->name('suppliers.update');
        });

        Route::middleware('branch')->group(function () {
            Route::get('/', HomeController::class)->name('home');

            // Purchasing for the branch being worked in.
            Route::middleware('branch.can:pharmacy.purchasing.view')->group(function () {
                Route::get('purchase-orders', [PurchaseOrderController::class, 'index'])->name('purchase-orders.index');
                Route::get('purchase-orders/{order}', [PurchaseOrderController::class, 'show'])->whereUuid('order')->name('purchase-orders.show');
                Route::get('receipts', [GoodsReceiptController::class, 'index'])->name('receipts.index');
                Route::get('receipts/{receipt}', [GoodsReceiptController::class, 'show'])->whereUuid('receipt')->name('receipts.show');
            });
            Route::middleware('branch.can:pharmacy.purchasing.manage')->group(function () {
                Route::get('purchase-orders/create', [PurchaseOrderController::class, 'create'])->name('purchase-orders.create');
                Route::post('purchase-orders', [PurchaseOrderController::class, 'store'])->name('purchase-orders.store');
                Route::post('purchase-orders/{order}/ordered', [PurchaseOrderController::class, 'markOrdered'])->whereUuid('order')->name('purchase-orders.ordered');
                Route::post('purchase-orders/{order}/cancel', [PurchaseOrderController::class, 'cancel'])->whereUuid('order')->name('purchase-orders.cancel');
            });
            Route::middleware('branch.can:pharmacy.purchasing.receive')->group(function () {
                Route::get('receipts/create', [GoodsReceiptController::class, 'create'])->name('receipts.create');
                Route::post('receipts', [GoodsReceiptController::class, 'store'])->name('receipts.store');
            });

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
