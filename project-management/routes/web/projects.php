<?php

use App\Http\Controllers\Projects\ChangeRequestController;
use App\Http\Controllers\Projects\DeliverableController;
use App\Http\Controllers\Projects\HomeController;
use App\Http\Controllers\Projects\ProgrammeController;
use App\Http\Controllers\Projects\ProjectController;
use App\Http\Controllers\Projects\RiskController;
use App\Http\Controllers\Projects\TeamController;
use App\Http\Controllers\Work\MyWorkController;
use App\Http\Controllers\Work\NotificationController;
use App\Http\Controllers\Work\SearchController;
use Illuminate\Support\Facades\Route;

/*
| Projects: the portfolio, setting projects up, their team, milestones and
| risks. Inside {slug}.domain (routes/web.php).
*/

Route::get('/', HomeController::class)->name('home');
Route::get('my-work', MyWorkController::class)->name('my-work');

// Someone's own notifications: no permission beyond being a member.
Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
Route::post('account/email-alerts', [NotificationController::class, 'emailAlerts'])->name('account.email-alerts');
Route::post('notifications/{notification}/open', [NotificationController::class, 'open'])->whereUuid('notification')->name('notifications.open');

Route::get('programmes', [ProgrammeController::class, 'index'])->can('projects.view')->name('programmes.index');
Route::get('programmes/{programme}', [ProgrammeController::class, 'show'])->whereUuid('programme')->can('projects.view')->name('programmes.show');

Route::middleware('can:projects.manage')->group(function () {
    Route::post('programmes', [ProgrammeController::class, 'store'])->name('programmes.store');
    Route::put('programmes/{programme}', [ProgrammeController::class, 'update'])->whereUuid('programme')->name('programmes.update');
    Route::post('programmes/{programme}/archive', [ProgrammeController::class, 'archive'])->whereUuid('programme')->name('programmes.archive');
    Route::post('programmes/{programme}/restore', [ProgrammeController::class, 'restore'])->whereUuid('programme')->name('programmes.restore');
    Route::post('programmes/{programme}/projects', [ProgrammeController::class, 'addProject'])->whereUuid('programme')->name('programmes.projects.store');
    Route::delete('programmes/{programme}/projects/{project}', [ProgrammeController::class, 'removeProject'])->whereUuid(['programme', 'project'])->name('programmes.projects.destroy');
    Route::get('projects/create', [ProjectController::class, 'create'])->name('projects.create');
    Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
});
// Each group of results checks the permission of the page it links to.
Route::get('search', SearchController::class)->middleware('throttle:60,1')->name('search');

Route::get('projects', [ProjectController::class, 'index'])->can('projects.view')->name('projects.index');

// One project and everything in it. Nested records resolve through the
// project (scoped bindings): another project's look unknown.
Route::prefix('projects/{project}')->whereUuid('project')->scopeBindings()->name('projects.')->group(function () {
    Route::middleware('can:projects.view')->group(function () {
        Route::get('/', [ProjectController::class, 'show'])->name('show');
        Route::get('team', [TeamController::class, 'show'])->name('team');
        Route::get('risks', [RiskController::class, 'index'])->name('risks.index');
        Route::get('deliverables', [DeliverableController::class, 'index'])->name('deliverables.index');
        Route::get('changes', [ChangeRequestController::class, 'index'])->name('changes.index');
        // Whoever raised a request, or someone who manages the project (checked inside).
        Route::post('changes/{changeRequest}/withdraw', [ChangeRequestController::class, 'withdraw'])->whereUuid('changeRequest')->name('changes.withdraw');
    });

    Route::middleware('can:projects.tasks.manage')->group(function () {
        Route::post('deliverables/{deliverable}/deliver', [DeliverableController::class, 'deliver'])->whereUuid('deliverable')->name('deliverables.deliver');
        Route::post('changes', [ChangeRequestController::class, 'store'])->name('changes.store');
    });

    Route::middleware('can:projects.changes.approve')->group(function () {
        Route::post('changes/{changeRequest}/approve', [ChangeRequestController::class, 'approve'])->whereUuid('changeRequest')->name('changes.approve');
        Route::post('changes/{changeRequest}/reject', [ChangeRequestController::class, 'reject'])->whereUuid('changeRequest')->name('changes.reject');
    });

    Route::post('changes/{changeRequest}/budget', [ChangeRequestController::class, 'restartBudget'])->whereUuid('changeRequest')->middleware(['can:projects.finance.view', 'can:projects.manage'])->name('changes.restart-budget');

    Route::middleware('can:projects.manage')->group(function () {
        Route::post('deliverables', [DeliverableController::class, 'store'])->name('deliverables.store');
        Route::put('deliverables/{deliverable}', [DeliverableController::class, 'update'])->whereUuid('deliverable')->name('deliverables.update');
        Route::post('deliverables/{deliverable}/accept', [DeliverableController::class, 'accept'])->whereUuid('deliverable')->name('deliverables.accept');
        Route::post('deliverables/{deliverable}/send-back', [DeliverableController::class, 'sendBack'])->whereUuid('deliverable')->name('deliverables.send-back');
        Route::post('deliverables/{deliverable}/cancel', [DeliverableController::class, 'cancel'])->whereUuid('deliverable')->name('deliverables.cancel');
        Route::get('edit', [ProjectController::class, 'edit'])->name('edit');
        Route::put('/', [ProjectController::class, 'update'])->name('update');
        Route::post('status', [ProjectController::class, 'status'])->name('status');
        Route::post('members', [TeamController::class, 'addMember'])->name('members.store');
        Route::delete('members/{member}', [TeamController::class, 'removeMember'])->whereUuid('member')->name('members.destroy');
        Route::post('milestones', [TeamController::class, 'addMilestone'])->name('milestones.store');
        Route::post('milestones/{milestone}/toggle', [TeamController::class, 'toggleMilestone'])->whereUuid('milestone')->name('milestones.toggle');
        Route::delete('milestones/{milestone}', [TeamController::class, 'removeMilestone'])->whereUuid('milestone')->name('milestones.destroy');
        Route::post('risks', [RiskController::class, 'store'])->name('risks.store');
        Route::put('risks/{risk}', [RiskController::class, 'update'])->whereUuid('risk')->name('risks.update');
    });

    require __DIR__.'/work.php';
    require __DIR__.'/finance.php';
});
