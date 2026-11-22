<?php

use App\Http\Controllers\AuditController;
use App\Http\Controllers\EntryController;
use App\Http\Controllers\ExceptionController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LogbookController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PeopleController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ResourceController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\SheetController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TrackController;
use App\Http\Middleware\RememberMember;
use Illuminate\Support\Facades\Route;
use Miles\PlatformClient\PlatformConfig;

/*
|--------------------------------------------------------------------------
| Miles Logbook (ADR-0025)
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
    ->whereUuid(['entry', 'attachment', 'logbook', 'sheet', 'grant', 'task', 'series', 'file', 'exception', 'schedule', 'occurrence', 'notification', 'resource', 'location'])
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

        // Operations (ADR-0026). Tasks: the assignee works them; tasks.manage gives, follows up and verifies.
        Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index');
        Route::get('tasks/{task}', [TaskController::class, 'show'])->name('tasks.show');
        Route::post('tasks/{task}/start', [TaskController::class, 'start'])->name('tasks.start');
        Route::post('tasks/{task}/complete', [TaskController::class, 'complete'])->name('tasks.complete');
        Route::post('tasks/{task}/files', [TaskController::class, 'upload'])->middleware('throttle:30,1')->name('tasks.files.store');
        Route::delete('task-files/{file}', [TaskController::class, 'removeFile'])->name('tasks.files.destroy');
        Route::get('work-files/{file}', [TaskController::class, 'download'])->name('work-files.show');
        Route::middleware('can:logbook.tasks.manage')->group(function () {
            Route::post('tasks', [TaskController::class, 'store'])->middleware('throttle:60,1')->name('tasks.store');
            Route::put('tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
            Route::post('tasks/{task}/assign', [TaskController::class, 'assign'])->name('tasks.assign');
            Route::post('tasks/{task}/verify', [TaskController::class, 'verify'])->name('tasks.verify');
            Route::post('tasks/{task}/cancel', [TaskController::class, 'cancel'])->name('tasks.cancel');
            Route::post('tasks/{task}/reopen', [TaskController::class, 'reopen'])->name('tasks.reopen');
            Route::post('task-series', [TaskController::class, 'storeSeries'])->name('task-series.store');
            Route::put('task-series/{series}', [TaskController::class, 'updateSeries'])->name('task-series.update');
            Route::post('task-series/{series}/pause', [TaskController::class, 'pauseSeries'])->name('task-series.pause');
            Route::post('task-series/{series}/resume', [TaskController::class, 'resumeSeries'])->name('task-series.resume');
            Route::post('task-series/{series}/end', [TaskController::class, 'endSeries'])->name('task-series.end');
        });

        // Exceptions: reported by those who record or review; followed up by tasks.manage.
        Route::get('exceptions', [ExceptionController::class, 'index'])->name('exceptions.index');
        Route::get('exceptions/{exception}', [ExceptionController::class, 'show'])->name('exceptions.show');
        Route::post('exceptions', [ExceptionController::class, 'store'])->middleware('throttle:60,1')->name('exceptions.store');
        Route::post('exceptions/{exception}/start', [ExceptionController::class, 'start'])->name('exceptions.start');
        Route::post('exceptions/{exception}/resolve', [ExceptionController::class, 'resolve'])->name('exceptions.resolve');
        Route::post('exceptions/{exception}/files', [ExceptionController::class, 'upload'])->middleware('throttle:30,1')->name('exceptions.files.store');
        Route::delete('exception-files/{file}', [ExceptionController::class, 'removeFile'])->name('exceptions.files.destroy');
        Route::middleware('can:logbook.tasks.manage')->group(function () {
            Route::post('exceptions/{exception}/assign', [ExceptionController::class, 'assign'])->name('exceptions.assign');
            Route::post('exceptions/{exception}/close', [ExceptionController::class, 'close'])->name('exceptions.close');
            Route::post('exceptions/{exception}/reopen', [ExceptionController::class, 'reopen'])->name('exceptions.reopen');
        });

        // Scheduled log sheets: everyone sees the expected entries they reach; managers set schedules.
        Route::get('schedules', [ScheduleController::class, 'index'])->name('schedules.index');
        Route::middleware('can:logbook.manage')->group(function () {
            Route::post('sheets/{sheet}/schedules', [ScheduleController::class, 'store'])->name('schedules.store');
            Route::put('schedules/{schedule}', [ScheduleController::class, 'update'])->name('schedules.update');
            Route::post('schedules/{schedule}/pause', [ScheduleController::class, 'pause'])->name('schedules.pause');
            Route::post('schedules/{schedule}/resume', [ScheduleController::class, 'resume'])->name('schedules.resume');
            Route::post('schedules/{schedule}/end', [ScheduleController::class, 'end'])->name('schedules.end');
            Route::post('occurrences/{occurrence}/excuse', [ScheduleController::class, 'excuse'])->name('occurrences.excuse');
        });

        // Notifications: one's own only.
        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::get('notifications/recent', [NotificationController::class, 'recent'])->name('notifications.recent');
        Route::get('notifications/{notification}/open', [NotificationController::class, 'open'])->name('notifications.open');
        Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');
        Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
        Route::put('notifications/settings', [NotificationController::class, 'settings'])->name('notifications.settings');

        // Resources and locations: read by everyone, kept by managers.
        Route::get('resources', [ResourceController::class, 'index'])->name('resources.index');
        Route::middleware('can:logbook.manage')->group(function () {
            Route::post('resources', [ResourceController::class, 'store'])->name('resources.store');
            Route::put('resources/{resource}', [ResourceController::class, 'update'])->name('resources.update');
            Route::post('locations', [ResourceController::class, 'storeLocation'])->name('locations.store');
            Route::put('locations/{location}', [ResourceController::class, 'updateLocation'])->name('locations.update');
        });

        // Routes (GPS, ADR-0028): own routes for everyone; others' with logbook.tracks.view.
        Route::get('routes', [TrackController::class, 'index'])->name('tracks.index');
        Route::get('routes/{track}', [TrackController::class, 'show'])->whereUuid('track')->name('tracks.show');
        Route::get('routes/{track}/gpx', [TrackController::class, 'export'])->whereUuid('track')->name('tracks.export');
        Route::delete('routes/{track}', [TrackController::class, 'destroy'])->whereUuid('track')->middleware('can:logbook.manage')->name('tracks.destroy');
        // Coming soon (ADR-0028 phases 2 and 3): pages only.
        Route::get('coming-soon/{page}', [TrackController::class, 'comingSoon'])->whereIn('page', ['gps-devices', 'fleet'])->name('coming-soon');

        Route::get('audit', AuditController::class)->middleware('can:logbook.audit.view')->name('audit.index');
    });
