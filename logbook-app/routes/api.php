<?php

use App\Http\Controllers\Api\EntryController;
use App\Http\Controllers\Api\KitController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Middleware\RememberMember;
use Illuminate\Support\Facades\Route;
use Miles\PlatformClient\PlatformConfig;

/*
|--------------------------------------------------------------------------
| The phone app's API (ADR-0025 §8)
|--------------------------------------------------------------------------
|
| {workspace}/logbook/api/v1. platform.api checks the Central access token
| and the member's access to Logbook on every request; the app first calls
| POST {workspace}/logbook/api/session (platform-client). The same rules
| as the web apply: catalog permission as the ceiling, logbook and sheet
| grants inside it, 404 for anything out of reach.
|
| Every write is safe to repeat: an entry is keyed by the phone's id, a
| file by its field and hash, a save and a submission by the revision they
| start from.
|
*/

if (! app(PlatformConfig::class)->configured()) {
    return;
}

Route::prefix(app(PlatformConfig::class)->path().'/api/v1')
    ->middleware(['platform.api', RememberMember::class, 'throttle:logbook-api'])
    ->name('api.')
    ->group(function () {
        Route::get('me', [KitController::class, 'me'])->name('me');
        Route::get('kit', [KitController::class, 'kit'])->name('kit');
        Route::get('sheet-versions/{version}', [KitController::class, 'version'])->whereUuid('version')->name('versions.show');

        Route::middleware('can:logbook.entries.create')->group(function () {
            Route::get('entries', [EntryController::class, 'index'])->name('entries.index');
            Route::post('entries', [EntryController::class, 'store'])->name('entries.store');
            Route::put('entries/{entry}', [EntryController::class, 'update'])->whereUuid('entry')->name('entries.update');
            Route::post('entries/{entry}/submit', [EntryController::class, 'submit'])->whereUuid('entry')->name('entries.submit');
            Route::delete('entries/{entry}', [EntryController::class, 'destroy'])->whereUuid('entry')->name('entries.destroy');
            Route::post('entries/{entry}/attachments', [EntryController::class, 'attach'])->whereUuid('entry')->name('entries.attach');
            Route::delete('attachments/{attachment}', [EntryController::class, 'detach'])->whereUuid('attachment')->name('attachments.destroy');
        });

        Route::get('entries/{entry}', [EntryController::class, 'show'])->whereUuid('entry')->name('entries.show');
        Route::get('attachments/{attachment}', [EntryController::class, 'download'])->whereUuid('attachment')->name('attachments.show');

        Route::middleware('can:logbook.entries.review')->group(function () {
            Route::get('reviews', [ReviewController::class, 'index'])->name('reviews.index');
            Route::post('entries/{entry}/review', [ReviewController::class, 'decide'])->whereUuid('entry')->name('reviews.decide');
        });
    });
