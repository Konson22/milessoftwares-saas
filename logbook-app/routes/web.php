<?php

use App\Http\Controllers\AuditController;
use App\Http\Controllers\EntryController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LogbookController;
use App\Http\Controllers\PeopleController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\SheetController;
use App\Http\Middleware\RememberMember;
use Illuminate\Support\Facades\Route;
use Miles\PlatformClient\PlatformConfig;

/*
|--------------------------------------------------------------------------
| Tatook Logbook (ADR-0025)
|--------------------------------------------------------------------------
|
| Served at {workspace}/logbook. The "platform" middleware resolves the
| workspace from the host and requires a signed-in member whose platform
| access allows Logbook (an unentitled workspace answers 404). Each route
| checks its catalog permission; the domain (App\Logbook) checks which
| logbooks and sheets the member reaches (404 outside), lifecycle state
| and separation of duties.
|
*/

// Not connected yet (fresh checkout, no .env): boot without product routes.
if (! app(PlatformConfig::class)->configured()) {
    return;
}

Route::prefix(app(PlatformConfig::class)->path())
    ->middleware(['web', 'platform', RememberMember::class])
    ->whereUuid(['entry', 'attachment', 'logbook', 'sheet', 'grant'])
    ->group(function () {
        Route::get('/', HomeController::class)->name('home');

        // Entries: readers reach what Access::entries() allows; authors change their own.
        Route::get('entries', [EntryController::class, 'index'])->name('entries.index');
        Route::get('entries/{entry}', [EntryController::class, 'show'])->name('entries.show');
        Route::get('attachments/{attachment}', [EntryController::class, 'download'])->name('attachments.show');
        Route::get('entries/{entry}/pdf', [EntryController::class, 'pdf'])->middleware('throttle:30,1')->name('entries.pdf');
        Route::middleware('can:logbook.entries.create')->group(function () {
            Route::get('entries/new', [EntryController::class, 'create'])->name('entries.create');
            Route::post('entries', [EntryController::class, 'store'])->middleware('throttle:60,1')->name('entries.store');
            Route::get('entries/{entry}/edit', [EntryController::class, 'edit'])->name('entries.edit');
            Route::put('entries/{entry}', [EntryController::class, 'update'])->name('entries.update');
            Route::post('entries/{entry}/submit', [EntryController::class, 'submit'])->name('entries.submit');
            Route::delete('entries/{entry}', [EntryController::class, 'destroy'])->name('entries.destroy');
            Route::post('entries/{entry}/attachments', [EntryController::class, 'attach'])->middleware('throttle:30,1')->name('attachments.store');
            Route::delete('attachments/{attachment}', [EntryController::class, 'detach'])->name('attachments.destroy');
        });

        // Reviews: never one's own entry (App\Logbook\Entries::review).
        Route::middleware('can:logbook.entries.review')->group(function () {
            Route::get('reviews', [ReviewController::class, 'index'])->name('reviews.index');
            Route::post('entries/{entry}/review', [ReviewController::class, 'decide'])->name('reviews.decide');
        });

        // Logbooks and sheets: read within reach; configured by managers.
        Route::get('logbooks', [LogbookController::class, 'index'])->name('logbooks.index');
        Route::get('logbooks/{logbook}', [LogbookController::class, 'show'])->name('logbooks.show');
        Route::get('sheets/{sheet}', [SheetController::class, 'show'])->name('sheets.show');
        Route::middleware('can:logbook.manage')->group(function () {
            Route::post('logbooks', [LogbookController::class, 'store'])->name('logbooks.store');
            Route::put('logbooks/{logbook}', [LogbookController::class, 'update'])->name('logbooks.update');
            Route::post('logbooks/{logbook}/archive', [LogbookController::class, 'archive'])->name('logbooks.archive');
            Route::post('logbooks/{logbook}/restore', [LogbookController::class, 'restore'])->name('logbooks.restore');
            Route::post('logbooks/{logbook}/access', [LogbookController::class, 'grant'])->name('logbooks.access.store');
            Route::delete('access/{grant}', [LogbookController::class, 'revoke'])->name('logbooks.access.destroy');

            Route::post('logbooks/{logbook}/sheets', [SheetController::class, 'store'])->name('sheets.store');
            Route::get('logbooks/{logbook}/starters', [SheetController::class, 'starters'])->name('sheets.starters');
            Route::post('logbooks/{logbook}/starters', [SheetController::class, 'fromStarter'])->name('sheets.from-starter');
            Route::get('sheets/{sheet}/template', [SheetController::class, 'template'])->name('sheets.template');
            Route::put('sheets/{sheet}', [SheetController::class, 'update'])->name('sheets.update');
            Route::put('sheets/{sheet}/draft', [SheetController::class, 'saveDraft'])->name('sheets.draft.save');
            Route::delete('sheets/{sheet}/draft', [SheetController::class, 'discardDraft'])->name('sheets.draft.destroy');
            Route::post('sheets/{sheet}/publish', [SheetController::class, 'publish'])->name('sheets.publish');
            Route::post('sheets/{sheet}/duplicate', [SheetController::class, 'duplicate'])->name('sheets.duplicate');
            Route::post('sheets/{sheet}/archive', [SheetController::class, 'archive'])->name('sheets.archive');
            Route::post('sheets/{sheet}/restore', [SheetController::class, 'restore'])->name('sheets.restore');
        });

        // People: invite, add and give Logbook roles from Logbook itself
        // (Central decides; ApplicationPeopleManagers lets the Manager through).
        Route::middleware('can:logbook.people.manage')->prefix('people')->name('people.')->group(function () {
            Route::get('/', [PeopleController::class, 'index'])->name('index');
            Route::post('invitations', [PeopleController::class, 'invite'])->middleware('throttle:30,60')->name('invite');
            Route::post('invitations/{invitation}/cancel', [PeopleController::class, 'cancelInvitation'])->whereUuid('invitation')->name('invitations.cancel');
            Route::post('accounts', [PeopleController::class, 'addAccount'])->middleware('throttle:30,60')->name('accounts.store');
            Route::post('roles', [PeopleController::class, 'giveRole'])->name('roles.store');
            Route::post('roles/revoke', [PeopleController::class, 'takeBackRole'])->name('roles.revoke');
        });

        // Reports and exports, within the logbooks the member reaches.
        Route::middleware('can:logbook.reports.view')->group(function () {
            Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
            Route::get('reports/entries.csv', [ReportController::class, 'csv'])->middleware('throttle:10,1')->name('reports.csv');
            Route::get('reports/entries.pdf', [ReportController::class, 'pdf'])->middleware('throttle:10,1')->name('reports.pdf');
        });

        Route::get('audit', AuditController::class)->middleware('can:logbook.audit.view')->name('audit.index');
    });
