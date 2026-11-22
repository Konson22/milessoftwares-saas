<?php

use App\Http\Controllers\Api\EntryController;
use App\Http\Controllers\Api\KitController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\TrackController;
use App\Http\Controllers\Api\WorkController;
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

        // Operations (ADR-0026): the member's tasks, due inspections, notifications.
        Route::get('tasks', [WorkController::class, 'tasks'])->name('tasks.index');
        Route::get('tasks/{task}', [WorkController::class, 'task'])->whereUuid('task')->name('tasks.show');
        Route::post('tasks/{task}/start', [WorkController::class, 'start'])->whereUuid('task')->name('tasks.start');
        Route::post('tasks/{task}/complete', [WorkController::class, 'complete'])->whereUuid('task')->name('tasks.complete');
        Route::post('tasks/{task}/evidence', [WorkController::class, 'evidence'])->whereUuid('task')->name('tasks.evidence');
        Route::get('due', [WorkController::class, 'due'])->name('due');
        Route::get('notifications', [WorkController::class, 'notifications'])->name('notifications.index');
        Route::post('notifications/read', [WorkController::class, 'read'])->name('notifications.read');

        // Push (ADR-0027): the phone's token, registered after sign-in and removed at sign-out.
        Route::post('push-devices', [WorkController::class, 'registerDevice'])->middleware('throttle:30,1')->name('push-devices.store');
        Route::post('push-devices/remove', [WorkController::class, 'unregisterDevice'])->name('push-devices.destroy');

        // Routes (GPS, ADR-0028): recorded by the person on their phone, sent in batches.
        Route::middleware('can:logbook.entries.create')->group(function () {
            Route::get('tracks', [TrackController::class, 'index'])->name('tracks.index');
            Route::post('tracks', [TrackController::class, 'upsert'])->middleware('throttle:120,1')->name('tracks.upsert');
            Route::post('tracks/{track}/points', [TrackController::class, 'points'])->whereUuid('track')->middleware('throttle:240,1')->name('tracks.points');
        });
    });
