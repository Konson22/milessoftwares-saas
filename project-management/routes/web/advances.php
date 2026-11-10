<?php

use App\Http\Controllers\Finance\StaffAdvanceController;
use Illuminate\Support\Facades\Route;

/*
| Staff advances. Inside {slug}.domain (routes/web.php). Everyone reaches their
| own advance; the rules are in App\Actions\StaffAdvances.
*/

Route::get('/', [StaffAdvanceController::class, 'index'])->name('index');
Route::post('/', [StaffAdvanceController::class, 'store'])->can('finance.advances.request')->name('store');

Route::prefix('{advance}')->whereUuid('advance')->scopeBindings()->group(function () {
    // Its holder, or someone who may view advances (checked inside).
    Route::get('/', [StaffAdvanceController::class, 'show'])->name('show');
    Route::get('lines/{line}/receipt', [StaffAdvanceController::class, 'receipt'])->withoutScopedBindings()->whereUuid('line')->name('lines.receipt');
    Route::post('cancel', [StaffAdvanceController::class, 'cancel'])->name('cancel');

    Route::middleware('can:finance.advances.request')->group(function () {
        Route::post('lines', [StaffAdvanceController::class, 'addLine'])->name('lines.store');
        Route::delete('lines/{line}', [StaffAdvanceController::class, 'removeLine'])->withoutScopedBindings()->whereUuid('line')->name('lines.destroy');
        Route::post('retirements', [StaffAdvanceController::class, 'submitRetirement'])->name('retirements.store');
    });

    Route::middleware('can:finance.advances.approve')->group(function () {
        Route::post('approve', [StaffAdvanceController::class, 'approve'])->name('approve');
        Route::post('reject', [StaffAdvanceController::class, 'reject'])->name('reject');
        Route::post('retirements/{retirement}/approve', [StaffAdvanceController::class, 'approveRetirement'])->whereUuid('retirement')->name('retirements.approve');
        Route::post('retirements/{retirement}/return', [StaffAdvanceController::class, 'returnRetirement'])->whereUuid('retirement')->name('retirements.return');
        Route::post('settlements/{settlement}/approve', [StaffAdvanceController::class, 'approveWriteOff'])->whereUuid('settlement')->name('settlements.approve');
        Route::post('settlements/{settlement}/reject', [StaffAdvanceController::class, 'rejectWriteOff'])->whereUuid('settlement')->name('settlements.reject');
    });

    Route::middleware('can:finance.advances.disburse')->group(function () {
        Route::post('disburse', [StaffAdvanceController::class, 'disburse'])->name('disburse');
        Route::post('returns', [StaffAdvanceController::class, 'recordReturn'])->name('returns.store');
        Route::post('write-offs', [StaffAdvanceController::class, 'requestWriteOff'])->name('write-offs.store');
    });
});
