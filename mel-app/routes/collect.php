<?php

use App\Http\Controllers\Collect\AssignmentController;
use App\Http\Controllers\Collect\FormController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Data collection pages (ADR-0012)
|--------------------------------------------------------------------------
|
| Served at {workspace}/mel/collect, inside the M&E pages' middleware
| (routes/web.php). Each route checks its catalog permission; controllers
| check the form, programme and unit the member reaches (App\Collect\Scope)
| and answer 404 for anything outside them.
|
*/

Route::prefix('collect')->name('collect.')->group(function () {
    // Forms & surveys
    Route::middleware('can:mel.collect.view')->group(function () {
        Route::get('forms', [FormController::class, 'index'])->name('forms.index');
        Route::get('forms/create', [FormController::class, 'create'])->middleware('can:mel.collect.design')->name('forms.create');
        Route::get('forms/{form}', [FormController::class, 'show'])->name('forms.show');
        Route::get('forms/{form}/versions/{version}', [FormController::class, 'version'])->name('forms.version');
    });

    Route::middleware('can:mel.collect.design')->group(function () {
        Route::post('forms', [FormController::class, 'store'])->name('forms.store');
        Route::get('forms/{form}/edit', [FormController::class, 'edit'])->name('forms.edit');
        Route::put('forms/{form}', [FormController::class, 'update'])->name('forms.update');
        Route::get('forms/{form}/build', [FormController::class, 'build'])->name('forms.build');
        Route::put('forms/{form}/draft', [FormController::class, 'saveDraft'])->name('forms.draft');
        Route::post('forms/{form}/duplicate', [FormController::class, 'duplicate'])->name('forms.duplicate');
    });

    Route::middleware('can:mel.collect.publish')->group(function () {
        Route::post('forms/{form}/publish', [FormController::class, 'publish'])->name('forms.publish');
        Route::post('forms/{form}/archive', [FormController::class, 'archive'])->name('forms.archive');
        Route::post('forms/{form}/restore', [FormController::class, 'restore'])->name('forms.restore');
        Route::post('forms/{form}/versions/{version}/close', [FormController::class, 'closeVersion'])->name('forms.versions.close');
    });

    // Field assignments
    Route::get('assignments', [AssignmentController::class, 'index'])->middleware('can:mel.collect.view')->name('assignments.index');
    Route::middleware('can:mel.collect.assign')->group(function () {
        Route::post('forms/{form}/assignments', [AssignmentController::class, 'store'])->name('assignments.store');
        Route::post('assignments/{assignment}/close', [AssignmentController::class, 'close'])->name('assignments.close');
        Route::post('assignments/{assignment}/revoke', [AssignmentController::class, 'revoke'])->name('assignments.revoke');
    });
});
