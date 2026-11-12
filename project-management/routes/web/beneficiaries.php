<?php

use App\Http\Controllers\Funding\BeneficiaryController;
use App\Http\Controllers\Funding\DonorDashboardController;
use App\Http\Controllers\Funding\DonorWorkController;
use Illuminate\Support\Facades\Route;

/*
| The beneficiaries and implementers an organization that funds others works
| with, and the grants they share with it (RequireFunding, in routes/web.php);
| BeneficiaryController checks each against the donor.
*/

// The funding dashboard: home for an organization that only funds others.
Route::get('funding', DonorDashboardController::class)->name('beneficiaries.dashboard');

Route::prefix('beneficiaries')->name('beneficiaries.')->group(function () {
    Route::get('/', [BeneficiaryController::class, 'index'])->can('grants.view')->name('index');
    Route::get('grants', [DonorWorkController::class, 'grants'])->can('grants.view')->name('grants.index');
    Route::get('reports', [DonorWorkController::class, 'reports'])->can('grants.view')->name('reports');
    Route::get('projects', [DonorWorkController::class, 'projects'])->can('grants.view')->name('projects');
    Route::get('tasks', [DonorWorkController::class, 'tasks'])->can('grants.view')->name('tasks');

    Route::middleware('can:organization.manage')->group(function () {
        Route::post('/', [BeneficiaryController::class, 'store'])->middleware('throttle:30,1')->name('store');
        Route::post('workspaces', [BeneficiaryController::class, 'createWorkspace'])->middleware('throttle:20,60')->name('workspaces.store');
        Route::post('{partnership}/resend', [BeneficiaryController::class, 'resend'])->whereUuid('partnership')->middleware('throttle:30,1')->name('resend');
        Route::delete('{partnership}', [BeneficiaryController::class, 'destroy'])->whereUuid('partnership')->name('destroy');
    });

    Route::prefix('{partnership}')->whereUuid('partnership')->middleware('can:grants.view')->group(function () {
        Route::get('/', [BeneficiaryController::class, 'show'])->name('show');

        Route::prefix('grants/{grant}')->whereUuid('grant')->name('grants.')->group(function () {
            Route::get('/', [BeneficiaryController::class, 'grant'])->name('show');
            Route::post('messages', [BeneficiaryController::class, 'post'])->middleware(['can:grants.manage', 'throttle:20,1'])->name('messages.store');
            Route::post('reports/{report}/decision', [BeneficiaryController::class, 'decide'])->whereUuid('report')->middleware('can:grants.manage')->name('reports.decide');
            Route::get('reports/{report}/documents/{document}', [BeneficiaryController::class, 'document'])->whereUuid(['report', 'document'])->name('reports.document');
        });
    });
});
