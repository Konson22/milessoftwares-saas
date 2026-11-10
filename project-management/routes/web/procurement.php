<?php

use App\Http\Controllers\Work\ProcurementController;
use Illuminate\Support\Facades\Route;

/*
| Procurement. Inside {slug}.domain (routes/web.php). Each step is held apart by
| permission; the money goes through App\Actions\Spending and its own routes.
*/

Route::prefix('procurement')->name('procurement.')->group(function () {
    Route::middleware('can:procurement.request')->group(function () {
        Route::get('create', [ProcurementController::class, 'create'])->name('create');
        Route::post('/', [ProcurementController::class, 'store'])->name('store');
    });

    // Whoever may ask sees their own; procurement.view sees everyone's. The controller decides.
    Route::get('/', [ProcurementController::class, 'index'])->name('index');
    Route::get('{purchaseRequest}', [ProcurementController::class, 'show'])->whereUuid('purchaseRequest')->name('show');

    Route::prefix('{purchaseRequest}')->whereUuid('purchaseRequest')->scopeBindings()->group(function () {
        Route::middleware('can:procurement.view')->get('quotes/{quote}/document', [ProcurementController::class, 'quoteDocument'])->whereUuid('quote')->name('quotes.document');

        Route::middleware('can:procurement.request')->group(function () {
            Route::post('submit', [ProcurementController::class, 'submit'])->name('submit');
            Route::post('cancel', [ProcurementController::class, 'cancel'])->name('cancel');
        });

        Route::middleware('can:procurement.approve')->group(function () {
            Route::post('approve', [ProcurementController::class, 'approve'])->name('approve');
            Route::post('reject', [ProcurementController::class, 'reject'])->name('reject');
        });

        Route::middleware('can:procurement.manage')->group(function () {
            Route::post('quotes', [ProcurementController::class, 'addQuote'])->name('quotes.store');
            Route::delete('quotes/{quote}', [ProcurementController::class, 'removeQuote'])->whereUuid('quote')->name('quotes.destroy');
            Route::post('quotes/{quote}/choose', [ProcurementController::class, 'choose'])->whereUuid('quote')->name('quotes.choose');
            Route::post('orders', [ProcurementController::class, 'raiseOrder'])->name('orders.store');
            Route::post('orders/{order}/receipts', [ProcurementController::class, 'receive'])->whereUuid('order')->name('orders.receive');
            Route::post('orders/{order}/invoice', [ProcurementController::class, 'recordInvoice'])->whereUuid('order')->name('orders.invoice');
        });
    });
});
