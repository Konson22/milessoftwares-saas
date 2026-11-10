<?php

use App\Http\Controllers\Funding\FunderController;
use App\Http\Controllers\Funding\GrantController;
use App\Http\Controllers\Funding\GrantFundingController;
use App\Http\Controllers\Funding\GrantPortalController;
use Illuminate\Support\Facades\Route;

/*
| Funding: funders and grants, from opportunity to closeout. Inside
| {slug}.domain (routes/web.php).
*/

Route::middleware('can:grants.view')->group(function () {
    Route::get('funders', [FunderController::class, 'index'])->name('funders.index');
    Route::get('grants', [GrantController::class, 'index'])->name('grants.index');
    Route::get('grants/export', [GrantController::class, 'export'])->name('grants.export');
    Route::get('grants/{grant}', [GrantController::class, 'show'])->whereUuid('grant')->name('grants.show');
    Route::get('grants/{grant}/reports/{report}/documents/{document}', [GrantPortalController::class, 'document'])->whereUuid(['grant', 'report', 'document'])->scopeBindings()->name('grants.reports.documents.show');
});

Route::middleware('can:grants.manage')->group(function () {
    Route::get('funders/create', [FunderController::class, 'create'])->name('funders.create');
    Route::post('funders', [FunderController::class, 'store'])->name('funders.store');
    Route::get('funders/{funder}/edit', [FunderController::class, 'edit'])->whereUuid('funder')->name('funders.edit');
    Route::put('funders/{funder}', [FunderController::class, 'update'])->whereUuid('funder')->name('funders.update');

    Route::get('grants/create', [GrantController::class, 'create'])->name('grants.create');
    Route::post('grants', [GrantController::class, 'store'])->name('grants.store');

    // One grant and what hangs off it; nested records resolve through it.
    Route::prefix('grants/{grant}')->whereUuid('grant')->scopeBindings()->name('grants.')->group(function () {
        Route::get('edit', [GrantController::class, 'edit'])->name('edit');
        Route::put('/', [GrantController::class, 'update'])->name('update');
        Route::post('apply', [GrantController::class, 'apply'])->name('apply');
        Route::post('award', [GrantController::class, 'award'])->name('award');
        Route::post('decline', [GrantController::class, 'decline'])->name('decline');
        Route::post('close', [GrantController::class, 'close'])->name('close');
        Route::post('amendments', [GrantController::class, 'amend'])->name('amendments.store');

        Route::post('allocations', [GrantFundingController::class, 'allocate'])->name('allocations.store');
        Route::put('allocations/{allocation}', [GrantFundingController::class, 'updateAllocation'])->whereUuid('allocation')->name('allocations.update');
        Route::delete('allocations/{allocation}', [GrantFundingController::class, 'removeAllocation'])->whereUuid('allocation')->name('allocations.destroy');

        Route::post('disbursements', [GrantFundingController::class, 'schedule'])->name('disbursements.store');
        Route::post('disbursements/{disbursement}/receive', [GrantFundingController::class, 'receive'])->whereUuid('disbursement')->name('disbursements.receive');
        Route::delete('disbursements/{disbursement}', [GrantFundingController::class, 'unschedule'])->whereUuid('disbursement')->name('disbursements.destroy');

        Route::post('reports', [GrantFundingController::class, 'addReport'])->name('reports.store');

        Route::put('portal', [GrantPortalController::class, 'share'])->name('portal.share');
        Route::post('portal/contacts', [GrantPortalController::class, 'invite'])->middleware('throttle:20,1')->name('portal.contacts.store');
        Route::delete('portal/contacts/{contact}', [GrantPortalController::class, 'removeContact'])->whereUuid('contact')->withoutScopedBindings()->name('portal.contacts.destroy');
        Route::post('messages', [GrantPortalController::class, 'post'])->name('messages.store');
        Route::post('reports/{report}/documents', [GrantPortalController::class, 'attach'])->whereUuid('report')->name('reports.documents.store');
        Route::delete('reports/{report}/documents/{document}', [GrantPortalController::class, 'detach'])->whereUuid(['report', 'document'])->name('reports.documents.destroy');

        Route::post('reports/{report}/status', [GrantFundingController::class, 'moveReport'])->whereUuid('report')->name('reports.status');
        Route::delete('reports/{report}', [GrantFundingController::class, 'removeReport'])->whereUuid('report')->name('reports.destroy');
    });
});
