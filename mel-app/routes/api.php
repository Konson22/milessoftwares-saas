<?php

use App\Http\Controllers\Api\CollectController;
use App\Http\Middleware\RememberMember;
use App\Http\Middleware\SetUpMel;
use Illuminate\Support\Facades\Route;
use Miles\PlatformClient\PlatformConfig;

/*
|--------------------------------------------------------------------------
| The field app's API (ADR-0019 §6.2, §21)
|--------------------------------------------------------------------------
|
| {workspace}/mel/api/v1. platform.api checks the Central access token and
| the member's access to M&E on every request; the app first calls
| POST {workspace}/mel/api/session (platform-client). Collectors reach only
| the forms they are assigned to (CollectController).
|
*/

if (! app(PlatformConfig::class)->configured()) {
    return;
}

Route::prefix(app(PlatformConfig::class)->path().'/api/v1')
    ->middleware(['platform.api', SetUpMel::class, RememberMember::class, 'throttle:collect-api'])
    ->name('api.')
    ->group(function () {
        Route::prefix('collect')->name('collect.')->middleware('can:mel.collect.submit')->group(function () {
            Route::get('assignments', [CollectController::class, 'assignments'])->name('assignments');
            Route::get('form-versions/{version}', [CollectController::class, 'version'])->whereUuid('version')->name('versions.show');
            Route::get('submissions', [CollectController::class, 'index'])->name('submissions.index');
            Route::post('submissions', [CollectController::class, 'store'])->name('submissions.store');
            Route::get('submissions/{submission}', [CollectController::class, 'show'])->name('submissions.show');
            Route::put('submissions/{submission}/attachments/{question}', [CollectController::class, 'attach'])
                ->where('question', '[A-Za-z_][A-Za-z0-9_]{0,63}')->name('submissions.attachments');
            Route::post('submissions/{submission}/corrections', [CollectController::class, 'correct'])->name('submissions.corrections');
        });
    });
