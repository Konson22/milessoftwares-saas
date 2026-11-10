<?php

use App\Http\Controllers\Funding\DonorPortalController;
use Illuminate\Support\Facades\Route;

/*
| The donor portal, for those who act for a funder. On portal.domain
| (routes/web.php). No permission guards these: DonorPortalController answers
| only to a funder's contact, for the grants shared with them.
*/

Route::prefix('donor')->name('donor.')->group(function () {
    Route::get('/', [DonorPortalController::class, 'index'])->name('index');

    Route::prefix('grants/{grant}')->whereUuid('grant')->scopeBindings()->name('grants.')->group(function () {
        Route::get('/', [DonorPortalController::class, 'show'])->name('show');
        Route::post('messages', [DonorPortalController::class, 'post'])->middleware('throttle:20,1')->name('messages.store');
        Route::post('reports/{report}/decision', [DonorPortalController::class, 'decide'])->whereUuid('report')->name('reports.decide');
        Route::get('reports/{report}/documents/{document}', [DonorPortalController::class, 'document'])
            ->whereUuid(['report', 'document'])->name('reports.document');
    });
});
