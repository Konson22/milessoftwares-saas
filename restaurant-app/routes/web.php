<?php

use App\Http\Controllers\BillController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\DiningTableController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\KitchenController;
use App\Http\Controllers\MenuController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\TillController;
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
| signed-in member whose platform access allows this product. Pages behind
| "branch" work in the branch the member chose; "branch.can" checks a
| permission there (a role for another branch does not count).
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

        // The menu is the organization's, not a branch's.
        Route::get('menu', [MenuController::class, 'index'])->can('restaurant.menu.view')->name('menu.index');
        Route::middleware('can:restaurant.menu.manage')->group(function () {
            Route::get('menu/create', [MenuController::class, 'create'])->name('menu.create');
            Route::post('menu', [MenuController::class, 'store'])->name('menu.store');
            Route::get('menu/{item}/edit', [MenuController::class, 'edit'])->whereUuid('item')->name('menu.edit');
            Route::put('menu/{item}', [MenuController::class, 'update'])->whereUuid('item')->name('menu.update');
        });

        Route::middleware('branch')->group(function () {
            Route::get('/', HomeController::class)->name('home');

            // The branch's tables.
            Route::middleware('branch.can:restaurant.tables.manage')->group(function () {
                Route::get('tables', [DiningTableController::class, 'index'])->name('tables.index');
                Route::post('tables', [DiningTableController::class, 'store'])->name('tables.store');
                Route::put('tables/{table}', [DiningTableController::class, 'update'])->whereUuid('table')->name('tables.update');
            });

            // Orders: taken at the table, sent to the kitchen.
            Route::middleware('branch.can:restaurant.orders.take')->group(function () {
                Route::get('floor', [OrderController::class, 'floor'])->name('floor');
                Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
                Route::post('orders', [OrderController::class, 'store'])->name('orders.store');
                Route::get('orders/{order}', [OrderController::class, 'show'])->whereUuid('order')->name('orders.show');
                Route::post('orders/{order}/lines', [OrderController::class, 'addLines'])->whereUuid('order')->name('orders.lines.store');
                Route::delete('orders/{order}/lines/{line}', [OrderController::class, 'removeLine'])->whereUuid(['order', 'line'])->name('orders.lines.destroy');
                Route::post('orders/{order}/send', [OrderController::class, 'send'])->whereUuid('order')->name('orders.send');
                Route::post('orders/{order}/move', [OrderController::class, 'move'])->whereUuid('order')->name('orders.move');
                // Voiding what the kitchen has needs restaurant.orders.void (checked there).
                Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])->whereUuid('order')->name('orders.cancel');
            });
            Route::post('orders/{order}/lines/{line}/void', [OrderController::class, 'voidLine'])
                ->whereUuid(['order', 'line'])
                ->middleware('branch.can:restaurant.orders.void')
                ->name('orders.lines.void');
            // The bill, paid into the cashier's till.
            Route::post('orders/{order}/pay', [OrderController::class, 'pay'])
                ->whereUuid('order')
                ->middleware('branch.can:restaurant.bills.take')
                ->name('orders.pay');

            // The kitchen screen; waiters mark what they served.
            Route::middleware('branch.can:restaurant.kitchen.view')->group(function () {
                Route::get('kitchen', [KitchenController::class, 'index'])->name('kitchen');
                Route::post('kitchen/{ticket}/ready', [KitchenController::class, 'ready'])->whereUuid('ticket')->name('kitchen.ready');
            });
            Route::post('kitchen/{ticket}/served', [KitchenController::class, 'served'])
                ->whereUuid('ticket')
                ->middleware('branch.can:restaurant.kitchen.view,restaurant.orders.take')
                ->name('kitchen.served');

            // Paid bills.
            Route::middleware('branch.can:restaurant.bills.view')->group(function () {
                Route::get('bills', [BillController::class, 'index'])->name('bills.index');
                Route::get('bills/{bill}', [BillController::class, 'show'])->whereUuid('bill')->name('bills.show');
            });
            Route::post('bills/{bill}/void', [BillController::class, 'void'])
                ->whereUuid('bill')
                ->middleware('branch.can:restaurant.bills.void')
                ->name('bills.void');

            // Tills: cashiers their own, managers everyone's.
            Route::post('tills', [TillController::class, 'store'])->middleware('branch.can:restaurant.bills.take')->name('tills.store');
            Route::middleware('branch.can:restaurant.bills.take,restaurant.tills.manage')->group(function () {
                Route::get('tills', [TillController::class, 'index'])->name('tills.index');
                Route::get('tills/{till}', [TillController::class, 'show'])->whereUuid('till')->name('tills.show');
                Route::post('tills/{till}/close', [TillController::class, 'close'])->whereUuid('till')->name('tills.close');
            });

            // Drinks in stock at the branch (dishes keep none).
            Route::middleware('branch.can:restaurant.stock.view')->group(function () {
                Route::get('stock', [StockController::class, 'index'])->name('stock.index');
                Route::get('stock/{item}', [StockController::class, 'show'])->whereUuid('item')->name('stock.show');
            });
            Route::middleware('branch.can:restaurant.stock.adjust')->group(function () {
                Route::post('stock/{item}/opening', [StockController::class, 'opening'])->whereUuid('item')->name('stock.opening');
                Route::post('stock/{item}/adjustments', [StockController::class, 'adjust'])->whereUuid('item')->name('stock.adjust');
            });
        });
    });
