<?php

use App\Http\Controllers\BranchController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\Reports\CashierReportController;
use App\Http\Controllers\Reports\ProfitabilityReportController;
use App\Http\Controllers\Reports\PurchaseReportController;
use App\Http\Controllers\Reports\SalesAnalysisReportController;
use App\Http\Controllers\Reports\StockReportController;
use App\Modules\Inventory\Http\Controllers\DisposalController;
use App\Modules\Inventory\Http\Controllers\LocationController;
use App\Modules\Inventory\Http\Controllers\StockController;
use App\Modules\Inventory\Http\Controllers\StockCountController;
use App\Modules\Inventory\Http\Controllers\TransferController;
use App\Modules\MasterData\Http\Controllers\SupplierController;
use App\Modules\Procurement\Http\Controllers\GoodsReceiptController;
use App\Modules\Procurement\Http\Controllers\PurchaseOrderController;
use App\Modules\Procurement\Http\Controllers\SupplierReturnController;
use App\Modules\Retail\Http\Controllers\ProductController;
use App\Modules\Sales\Http\Controllers\CounterSettingsController;
use App\Modules\Sales\Http\Controllers\CustomerController;
use App\Modules\Sales\Http\Controllers\LoyaltyController;
use App\Modules\Sales\Http\Controllers\PriceListController;
use App\Modules\Sales\Http\Controllers\PromotionController;
use App\Modules\Sales\Http\Controllers\SaleController;
use App\Modules\Sales\Http\Controllers\SalesOrderController;
use App\Modules\Sales\Http\Controllers\SerialController;
use App\Modules\Sales\Http\Controllers\TillController;
use Illuminate\Support\Facades\Route;
use Miles\PlatformClient\PlatformConfig;

/*
|--------------------------------------------------------------------------
| Retail
|--------------------------------------------------------------------------
|
| The Retail industry module (ADR-0003), its own registry application served
| by this deployment at {workspace}/retail (PLATFORM_HOSTED=retail). Routes
| are named "retail.*". "platform.app:retail" makes every request (sign-in,
| access, events) the retail application's, so the shared controllers below
| work on products, check retail.* permissions and render retail/* pages.
|
*/

$path = app(PlatformConfig::class)->runAs('retail', fn () => app(PlatformConfig::class)->path());

Route::prefix($path)
    ->middleware(['web', 'platform.app:retail', 'platform'])
    ->name('retail.')
    ->group(function () {
        Route::get('branch', [BranchController::class, 'edit'])->name('branch.edit');
        Route::put('branch', [BranchController::class, 'update'])->name('branch.update');

        // The catalogue is the organization's, not a branch's.
        Route::get('products', [ProductController::class, 'index'])->can('retail.products.view')->name('products.index');
        Route::middleware('can:retail.products.manage')->group(function () {
            Route::get('products/create', [ProductController::class, 'create'])->name('products.create');
            Route::post('products', [ProductController::class, 'store'])->name('products.store');
            Route::get('products/{product}/edit', [ProductController::class, 'edit'])->whereUuid('product')->name('products.edit');
            Route::put('products/{product}', [ProductController::class, 'update'])->whereUuid('product')->name('products.update');
        });

        // Suppliers are the organization's, shared with the pharmacy.
        Route::get('suppliers', [SupplierController::class, 'index'])->can('retail.purchasing.view')->name('suppliers.index');
        Route::middleware('can:retail.purchasing.manage')->group(function () {
            Route::post('suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
            Route::put('suppliers/{supplier}', [SupplierController::class, 'update'])->whereUuid('supplier')->name('suppliers.update');
        });

        // Price lists are the organization's: customers buy at theirs in every branch.
        Route::middleware('can:retail.prices.manage')->group(function () {
            Route::get('price-lists', [PriceListController::class, 'index'])->name('price-lists.index');
            Route::post('price-lists', [PriceListController::class, 'store'])->name('price-lists.store');
            Route::get('price-lists/{priceList}', [PriceListController::class, 'show'])->whereUuid('priceList')->name('price-lists.show');
            Route::put('price-lists/{priceList}', [PriceListController::class, 'update'])->whereUuid('priceList')->name('price-lists.update');
            Route::put('price-lists/{priceList}/prices', [PriceListController::class, 'updatePrices'])->whereUuid('priceList')->name('price-lists.prices.update');
            // Promotions, given by the till by itself.
            Route::get('promotions', [PromotionController::class, 'index'])->name('promotions.index');
            Route::post('promotions', [PromotionController::class, 'store'])->name('promotions.store');
            Route::post('promotions/{promotion}/end', [PromotionController::class, 'end'])->whereUuid('promotion')->name('promotions.end');
            // The loyalty program: what earns a point, what a point is worth.
            Route::get('loyalty', [LoyaltyController::class, 'edit'])->name('loyalty.edit');
            Route::put('loyalty', [LoyaltyController::class, 'update'])->name('loyalty.update');
        });

        Route::middleware('branch')->group(function () {
            Route::get('/', HomeController::class)->name('home');

            // The counter of the branch being worked in.
            Route::middleware('branch.can:retail.sales.create')->group(function () {
                Route::get('sales/create', [SaleController::class, 'create'])->name('sales.create');
                Route::post('sales', [SaleController::class, 'store'])->name('sales.store');
            });
            Route::middleware('branch.can:retail.sales.view')->group(function () {
                Route::get('sales', [SaleController::class, 'index'])->name('sales.index');
                Route::get('sales/{sale}', [SaleController::class, 'show'])->whereUuid('sale')->name('sales.show');
                Route::get('sales/{sale}/receipt', [SaleController::class, 'receipt'])->whereUuid('sale')->name('sales.receipt');
            });
            Route::middleware('branch.can:retail.sales.void')->group(function () {
                Route::post('sales/{sale}/void', [SaleController::class, 'void'])->whereUuid('sale')->name('sales.void');
                Route::post('sales/{sale}/returns', [SaleController::class, 'storeReturn'])->whereUuid('sale')->name('sales.returns.store');
            });

            // Account customers: one account across branches; payments into this branch's till.
            Route::middleware('branch.can:retail.customers.view')->group(function () {
                Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
                Route::get('customers/{customer}', [CustomerController::class, 'show'])->whereUuid('customer')->name('customers.show');
            });
            Route::middleware('can:retail.customers.manage')->group(function () {
                Route::get('customers/create', [CustomerController::class, 'create'])->name('customers.create');
                Route::post('customers', [CustomerController::class, 'store'])->name('customers.store');
                Route::get('customers/{customer}/edit', [CustomerController::class, 'edit'])->whereUuid('customer')->name('customers.edit');
                Route::put('customers/{customer}', [CustomerController::class, 'update'])->whereUuid('customer')->name('customers.update');
            });
            Route::post('customers/{customer}/payments', [CustomerController::class, 'storePayment'])
                ->whereUuid('customer')
                ->middleware('branch.can:retail.sales.create')
                ->name('customers.payments.store');

            // Wholesale: quotations become orders, delivered (and invoiced) in parts.
            Route::middleware('branch.can:retail.orders.view')->group(function () {
                Route::get('orders', [SalesOrderController::class, 'index'])->name('orders.index');
                Route::get('orders/{order}', [SalesOrderController::class, 'show'])->whereUuid('order')->name('orders.show');
                Route::get('deliveries/{delivery}', [SalesOrderController::class, 'delivery'])->whereUuid('delivery')->name('deliveries.show');
            });
            Route::middleware('branch.can:retail.orders.manage')->group(function () {
                Route::get('orders/create', [SalesOrderController::class, 'create'])->name('orders.create');
                Route::post('orders', [SalesOrderController::class, 'store'])->name('orders.store');
                Route::get('orders/{order}/edit', [SalesOrderController::class, 'edit'])->whereUuid('order')->name('orders.edit');
                Route::put('orders/{order}', [SalesOrderController::class, 'update'])->whereUuid('order')->name('orders.update');
                Route::post('orders/{order}/confirm', [SalesOrderController::class, 'confirm'])->whereUuid('order')->name('orders.confirm');
                Route::post('orders/{order}/cancel', [SalesOrderController::class, 'cancel'])->whereUuid('order')->name('orders.cancel');
                Route::post('orders/{order}/close', [SalesOrderController::class, 'close'])->whereUuid('order')->name('orders.close');
            });
            Route::post('orders/{order}/deliveries', [SalesOrderController::class, 'deliver'])
                ->whereUuid('order')
                ->middleware('branch.can:retail.orders.deliver')
                ->name('orders.deliveries.store');

            // Tills: cashiers their own, managers everyone's.
            // The branch's counter: workflow, price level, customer rules, printing.
            Route::middleware('branch.can:retail.sales.settings')->group(function () {
                Route::get('counter-settings', [CounterSettingsController::class, 'edit'])->name('counter-settings.edit');
                // POST, not PUT: the form uploads a logo.
                Route::post('counter-settings', [CounterSettingsController::class, 'update'])->name('counter-settings.update');
            });
            Route::post('tills', [TillController::class, 'store'])->middleware('branch.can:retail.sales.create')->name('tills.store');
            Route::middleware('branch.can:retail.sales.create,retail.tills.manage')->group(function () {
                Route::get('tills', [TillController::class, 'index'])->name('tills.index');
                Route::get('tills/{till}', [TillController::class, 'show'])->whereUuid('till')->name('tills.show');
                Route::post('tills/{till}/close', [TillController::class, 'close'])->whereUuid('till')->name('tills.close');
                Route::post('tills/{till}/movements', [TillController::class, 'move'])->whereUuid('till')->name('tills.movements.store');
            });

            // Stock of the branch being worked in.
            Route::middleware('branch.can:retail.stock.view')->group(function () {
                Route::get('stock', [StockController::class, 'index'])->name('stock.index');
                Route::get('stock/{item}', [StockController::class, 'show'])->whereUuid('item')->name('stock.show');
                // The branch's shelves (shared with a pharmacy there), the shop's goods on them.
                Route::get('locations', [LocationController::class, 'index'])->name('locations.index');
                Route::get('locations/{location}', [LocationController::class, 'show'])->whereUuid('location')->name('locations.show');
                Route::get('counts', [StockCountController::class, 'index'])->name('counts.index');
                Route::get('serials', [SerialController::class, 'index'])->name('serials.index');
                Route::get('counts/{count}', [StockCountController::class, 'show'])->whereUuid('count')->name('counts.show');
            });
            Route::middleware('branch.can:retail.stock.view')->group(function () {
                Route::get('transfers', [TransferController::class, 'index'])->name('transfers.index');
                Route::get('transfers/{transfer}', [TransferController::class, 'show'])->whereUuid('transfer')->name('transfers.show');
            });
            Route::middleware('branch.can:retail.stock.adjust')->group(function () {
                Route::post('stock/{item}/opening', [StockController::class, 'storeOpening'])->whereUuid('item')->name('stock.opening');
                Route::post('batches/{batch}/adjustments', [StockController::class, 'storeAdjustment'])->whereUuid('batch')->name('stock.adjust');
                // Write-offs (damaged, expired, stolen): asked for here, decided by someone else.
                Route::post('batches/{batch}/disposals', [DisposalController::class, 'store'])->whereUuid('batch')->name('disposals.store');
                // Stock-takes: counted line by line, the differences posted on completion.
                Route::post('counts', [StockCountController::class, 'store'])->name('counts.store');
                Route::post('serials', [SerialController::class, 'store'])->name('serials.store');
                Route::put('counts/{count}', [StockCountController::class, 'update'])->whereUuid('count')->name('counts.update');
                Route::post('counts/{count}/complete', [StockCountController::class, 'complete'])->whereUuid('count')->name('counts.complete');
                Route::post('counts/{count}/cancel', [StockCountController::class, 'cancel'])->whereUuid('count')->name('counts.cancel');
                // Shelves, and putting goods away on them.
                Route::post('locations', [LocationController::class, 'store'])->name('locations.store');
                Route::put('locations/{location}', [LocationController::class, 'update'])->whereUuid('location')->name('locations.update');
                Route::post('batches/{batch}/move', [LocationController::class, 'move'])->whereUuid('batch')->name('locations.move');
                // Stock sent to another branch, received from one.
                Route::get('transfers/create', [TransferController::class, 'create'])->name('transfers.create');
                Route::post('transfers', [TransferController::class, 'store'])->name('transfers.store');
                Route::post('transfers/{transfer}/receive', [TransferController::class, 'receive'])->whereUuid('transfer')->name('transfers.receive');
                Route::post('transfers/{transfer}/cancel', [TransferController::class, 'cancel'])->whereUuid('transfer')->name('transfers.cancel');
            });
            // Batches held back from sale: quarantine (checking them), recall, release.
            Route::middleware('branch.can:retail.stock.hold')->group(function () {
                Route::post('batches/{batch}/hold', [StockController::class, 'hold'])->whereUuid('batch')->name('stock.hold');
                Route::post('batches/{batch}/release', [StockController::class, 'release'])->whereUuid('batch')->name('stock.release');
            });
            Route::get('disposals', [DisposalController::class, 'index'])
                ->middleware('branch.can:retail.stock.adjust,retail.stock.dispose')
                ->name('disposals.index');
            Route::middleware('branch.can:retail.stock.dispose')->group(function () {
                Route::post('disposals/{disposal}/approve', [DisposalController::class, 'approve'])->whereUuid('disposal')->name('disposals.approve');
                Route::post('disposals/{disposal}/reject', [DisposalController::class, 'reject'])->whereUuid('disposal')->name('disposals.reject');
            });

            // The branch's figures for a period: the shop's sales and stock only.
            Route::middleware('branch.can:retail.reports.view')->group(function () {
                Route::get('reports', ReportController::class)->name('reports.index');
                Route::get('reports/sales', SalesAnalysisReportController::class)->name('reports.sales');
                Route::get('reports/profitability', ProfitabilityReportController::class)->name('reports.profitability');
                Route::get('reports/stock', StockReportController::class)->name('reports.stock');
                Route::get('reports/purchases', PurchaseReportController::class)->name('reports.purchases');
                Route::get('reports/cashiers', CashierReportController::class)->name('reports.cashiers');
            });

            // Purchasing for the branch being worked in.
            Route::middleware('branch.can:retail.purchasing.view')->group(function () {
                Route::get('purchase-orders', [PurchaseOrderController::class, 'index'])->name('purchase-orders.index');
                Route::get('purchase-orders/{order}', [PurchaseOrderController::class, 'show'])->whereUuid('order')->name('purchase-orders.show');
                Route::get('receipts', [GoodsReceiptController::class, 'index'])->name('receipts.index');
                Route::get('receipts/{receipt}', [GoodsReceiptController::class, 'show'])->whereUuid('receipt')->name('receipts.show');
                Route::get('supplier-returns', [SupplierReturnController::class, 'index'])->name('supplier-returns.index');
                Route::get('supplier-returns/{return}', [SupplierReturnController::class, 'show'])->whereUuid('return')->name('supplier-returns.show');
            });
            Route::middleware('branch.can:retail.purchasing.manage')->group(function () {
                Route::get('purchase-orders/create', [PurchaseOrderController::class, 'create'])->name('purchase-orders.create');
                Route::post('purchase-orders', [PurchaseOrderController::class, 'store'])->name('purchase-orders.store');
                Route::post('purchase-orders/{order}/ordered', [PurchaseOrderController::class, 'markOrdered'])->whereUuid('order')->name('purchase-orders.ordered');
                Route::post('purchase-orders/{order}/cancel', [PurchaseOrderController::class, 'cancel'])->whereUuid('order')->name('purchase-orders.cancel');
            });
            Route::middleware('branch.can:retail.purchasing.receive')->group(function () {
                Route::get('receipts/create', [GoodsReceiptController::class, 'create'])->name('receipts.create');
                Route::post('receipts', [GoodsReceiptController::class, 'store'])->name('receipts.store');
                Route::get('receipts/{receipt}/return', [SupplierReturnController::class, 'create'])->whereUuid('receipt')->name('supplier-returns.create');
                Route::post('receipts/{receipt}/returns', [SupplierReturnController::class, 'store'])->whereUuid('receipt')->name('supplier-returns.store');
            });
        });
    });
