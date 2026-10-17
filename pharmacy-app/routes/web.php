<?php

use App\Http\Controllers\BranchController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\Reports\CashierReportController;
use App\Http\Controllers\Reports\ProfitabilityReportController;
use App\Http\Controllers\Reports\PurchaseReportController;
use App\Modules\Inventory\Http\Controllers\DisposalController;
use App\Modules\Inventory\Http\Controllers\StockController;
use App\Modules\MasterData\Http\Controllers\SupplierController;
use App\Modules\Pharmacy\Http\Controllers\ControlledRegisterController;
use App\Modules\Pharmacy\Http\Controllers\MedicineController;
use App\Modules\Pharmacy\Http\Controllers\PrescriptionController;
use App\Modules\Procurement\Http\Controllers\GoodsReceiptController;
use App\Modules\Procurement\Http\Controllers\PurchaseOrderController;
use App\Modules\Procurement\Http\Controllers\SupplierReturnController;
use App\Modules\Sales\Http\Controllers\SaleController;
use App\Modules\Sales\Http\Controllers\TillController;
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

            // The counter of the branch being worked in.
            Route::middleware('branch.can:pharmacy.sales.create')->group(function () {
                Route::get('sales/create', [SaleController::class, 'create'])->name('sales.create');
                Route::post('sales', [SaleController::class, 'store'])->name('sales.store');
            });
            Route::middleware('branch.can:pharmacy.sales.view')->group(function () {
                Route::get('sales', [SaleController::class, 'index'])->name('sales.index');
                Route::get('sales/{sale}', [SaleController::class, 'show'])->whereUuid('sale')->name('sales.show');
                Route::get('sales/{sale}/receipt', [SaleController::class, 'receipt'])->whereUuid('sale')->name('sales.receipt');
            });
            // Tills: cashiers their own, managers everyone's.
            Route::post('tills', [TillController::class, 'store'])->middleware('branch.can:pharmacy.sales.create')->name('tills.store');
            Route::middleware('branch.can:pharmacy.sales.create,pharmacy.tills.manage')->group(function () {
                Route::get('tills', [TillController::class, 'index'])->name('tills.index');
                Route::get('tills/{till}', [TillController::class, 'show'])->whereUuid('till')->name('tills.show');
                Route::post('tills/{till}/close', [TillController::class, 'close'])->whereUuid('till')->name('tills.close');
            });

            Route::middleware('branch.can:pharmacy.sales.void')->group(function () {
                Route::post('sales/{sale}/void', [SaleController::class, 'void'])->whereUuid('sale')->name('sales.void');
                Route::post('sales/{sale}/returns', [SaleController::class, 'storeReturn'])->whereUuid('sale')->name('sales.returns.store');
            });

            // Prescriptions: recorded, verified by a pharmacist, dispensed.
            Route::middleware('branch.can:pharmacy.prescriptions.view')->group(function () {
                Route::get('prescriptions', [PrescriptionController::class, 'index'])->name('prescriptions.index');
                Route::get('prescriptions/{prescription}', [PrescriptionController::class, 'show'])->whereUuid('prescription')->name('prescriptions.show');
            });
            Route::middleware('branch.can:pharmacy.prescriptions.record')->group(function () {
                Route::get('prescriptions/create', [PrescriptionController::class, 'create'])->name('prescriptions.create');
                Route::post('prescriptions', [PrescriptionController::class, 'store'])->name('prescriptions.store');
                Route::post('prescriptions/{prescription}/cancel', [PrescriptionController::class, 'cancel'])->whereUuid('prescription')->name('prescriptions.cancel');
            });
            Route::middleware('branch.can:pharmacy.prescriptions.verify')->group(function () {
                Route::post('prescriptions/{prescription}/verify', [PrescriptionController::class, 'verify'])->whereUuid('prescription')->name('prescriptions.verify');
                Route::post('prescriptions/{prescription}/reject', [PrescriptionController::class, 'reject'])->whereUuid('prescription')->name('prescriptions.reject');
            });
            Route::post('prescriptions/{prescription}/dispense', [PrescriptionController::class, 'dispense'])
                ->whereUuid('prescription')
                ->middleware('branch.can:pharmacy.sales.create')
                ->name('prescriptions.dispense');
            Route::get('controlled-register', ControlledRegisterController::class)
                ->middleware('branch.can:pharmacy.controlled.view')
                ->name('controlled.index');

            // The branch's figures for a period.
            Route::middleware('branch.can:pharmacy.reports.view')->group(function () {
                Route::get('reports', ReportController::class)->name('reports.index');
                Route::get('reports/profitability', ProfitabilityReportController::class)->name('reports.profitability');
                Route::get('reports/purchases', PurchaseReportController::class)->name('reports.purchases');
                Route::get('reports/cashiers', CashierReportController::class)->name('reports.cashiers');
            });

            // Purchasing for the branch being worked in.
            Route::middleware('branch.can:pharmacy.purchasing.view')->group(function () {
                Route::get('purchase-orders', [PurchaseOrderController::class, 'index'])->name('purchase-orders.index');
                Route::get('purchase-orders/{order}', [PurchaseOrderController::class, 'show'])->whereUuid('order')->name('purchase-orders.show');
                Route::get('receipts', [GoodsReceiptController::class, 'index'])->name('receipts.index');
                Route::get('receipts/{receipt}', [GoodsReceiptController::class, 'show'])->whereUuid('receipt')->name('receipts.show');
                Route::get('supplier-returns', [SupplierReturnController::class, 'index'])->name('supplier-returns.index');
                Route::get('supplier-returns/{return}', [SupplierReturnController::class, 'show'])->whereUuid('return')->name('supplier-returns.show');
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
                // Sending goods back to the supplier of a delivery.
                Route::get('receipts/{receipt}/return', [SupplierReturnController::class, 'create'])->whereUuid('receipt')->name('supplier-returns.create');
                Route::post('receipts/{receipt}/returns', [SupplierReturnController::class, 'store'])->whereUuid('receipt')->name('supplier-returns.store');
            });

            // Stock of the branch being worked in.
            Route::middleware('branch.can:pharmacy.stock.view')->group(function () {
                Route::get('stock', [StockController::class, 'index'])->name('stock.index');
                Route::get('stock/{item}', [StockController::class, 'show'])->whereUuid('item')->name('stock.show');
            });
            Route::middleware('branch.can:pharmacy.stock.adjust')->group(function () {
                Route::post('stock/{item}/opening', [StockController::class, 'storeOpening'])->whereUuid('item')->name('stock.opening');
                Route::post('batches/{batch}/adjustments', [StockController::class, 'storeAdjustment'])->whereUuid('batch')->name('stock.adjust');
                Route::post('batches/{batch}/disposals', [DisposalController::class, 'store'])->whereUuid('batch')->name('disposals.store');
            });
            // Batches held back from sale: quarantine, recall, release.
            Route::middleware('branch.can:pharmacy.stock.hold')->group(function () {
                Route::post('batches/{batch}/hold', [StockController::class, 'hold'])->whereUuid('batch')->name('stock.hold');
                Route::post('batches/{batch}/release', [StockController::class, 'release'])->whereUuid('batch')->name('stock.release');
            });
            // Disposals: asked for with stock.adjust, decided by someone else.
            Route::get('disposals', [DisposalController::class, 'index'])
                ->middleware('branch.can:pharmacy.stock.adjust,pharmacy.stock.dispose')
                ->name('disposals.index');
            Route::middleware('branch.can:pharmacy.stock.dispose')->group(function () {
                Route::post('disposals/{disposal}/approve', [DisposalController::class, 'approve'])->whereUuid('disposal')->name('disposals.approve');
                Route::post('disposals/{disposal}/reject', [DisposalController::class, 'reject'])->whereUuid('disposal')->name('disposals.reject');
            });
        });
    });
