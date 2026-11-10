<?php

use App\Http\Controllers\Finance\PettyCashController;
use Illuminate\Support\Facades\Route;

/*
| Petty cash boxes. Inside {slug}.domain (routes/web.php). Only a box's
| custodian pays from it, and nobody decides a top-up they asked for: the
| rules are in App\Actions\PettyCash.
*/

Route::get('/', [PettyCashController::class, 'index'])->can('finance.pettycash.view')->name('index');
Route::post('/', [PettyCashController::class, 'store'])->can('finance.pettycash.manage')->name('store');

Route::prefix('{fund}')->whereUuid('fund')->scopeBindings()->group(function () {
    Route::middleware('can:finance.pettycash.view')->group(function () {
        Route::get('/', [PettyCashController::class, 'show'])->name('show');
        Route::get('payments/{payment}/receipt', [PettyCashController::class, 'receipt'])->whereUuid('payment')->name('payments.receipt');
        // The custodian or a manager (checked inside).
        Route::post('payments/{payment}/reverse', [PettyCashController::class, 'reverse'])->whereUuid('payment')->name('payments.reverse');
        Route::post('top-ups', [PettyCashController::class, 'requestTopUp'])->name('top-ups.store');
    });

    Route::post('payments', [PettyCashController::class, 'pay'])->can('finance.pettycash.record')->name('payments.store');

    Route::middleware('can:finance.pettycash.approve')->group(function () {
        Route::post('top-ups/{topUp}/approve', [PettyCashController::class, 'approveTopUp'])->whereUuid('topUp')->name('top-ups.approve');
        Route::post('top-ups/{topUp}/reject', [PettyCashController::class, 'rejectTopUp'])->whereUuid('topUp')->name('top-ups.reject');
    });

    Route::middleware('can:finance.pettycash.manage')->group(function () {
        Route::post('counts', [PettyCashController::class, 'count'])->name('counts.store');
        Route::put('custodian', [PettyCashController::class, 'changeCustodian'])->name('custodian');
        Route::post('close', [PettyCashController::class, 'close'])->name('close');
        Route::post('move-to-finance', [PettyCashController::class, 'moveToFinance'])->name('move-to-finance');
    });
});
