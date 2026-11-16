<?php

use App\Http\Controllers\Collaboration\InvitationLinkController;
use App\Http\Controllers\Collaboration\ReportController;
use App\Http\Controllers\Collaboration\RequestController;
use App\Http\Controllers\Collaboration\SharedProjectController;
use App\Http\Controllers\Projects\CollaborationController;
use Illuminate\Support\Facades\Route;

/*
| Collaboration, in the participating organization's own workspace
| (ADR-0014): projects of other organizations it takes part in, invitations
| to it, who works on each, and sub-partners. A project it owns is managed
| under projects/{project}/collaboration (routes/web/projects.php).
| Every action checks authority again in App\Collaboration\Collaboration.
*/

// Each member sees the shared projects they work on; managers see them all.
Route::get('shared-projects', [SharedProjectController::class, 'index'])->name('shared-projects.index');
Route::get('shared-projects/{participant}', [SharedProjectController::class, 'show'])->whereUuid('participant')->name('shared-projects.show');

// Finding an organization to invite: to a project, or (a donor) to partner. The controller checks either permission.
Route::get('collaboration/organizations', [CollaborationController::class, 'organizations'])->middleware('throttle:60,1')->name('collaboration.organizations');

Route::middleware('can:projects.partners.manage')->group(function () {

    // An emailed invitation, opened in this workspace through its link.
    Route::get('project-invitations/{token}/open', [InvitationLinkController::class, 'show'])->where('token', '[A-Za-z0-9]{48}')->name('collaboration.show');
    Route::post('project-invitations/{token}/accept', [InvitationLinkController::class, 'accept'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:10,1')->name('collaboration.accept');
    Route::post('project-invitations/{token}/decline', [InvitationLinkController::class, 'decline'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:10,1')->name('collaboration.decline');

    Route::prefix('shared-projects')->name('shared-projects.')->group(function () {
        Route::post('invitations/{invitation}/accept', [SharedProjectController::class, 'accept'])->whereUuid('invitation')->middleware('throttle:10,1')->name('invitations.accept');
        Route::post('invitations/{invitation}/decline', [SharedProjectController::class, 'decline'])->whereUuid('invitation')->middleware('throttle:10,1')->name('invitations.decline');

        Route::prefix('{participant}')->whereUuid('participant')->group(function () {
            Route::post('representatives', [SharedProjectController::class, 'addRepresentative'])->name('representatives.store');
            Route::delete('representatives/{representative}', [SharedProjectController::class, 'removeRepresentative'])->whereUuid('representative')->name('representatives.destroy');
            Route::post('invitations', [SharedProjectController::class, 'invite'])->middleware('throttle:30,1')->name('invitations.store');
            Route::post('invitations/{invitation}/revoke', [SharedProjectController::class, 'revoke'])->whereUuid('invitation')->name('invitations.revoke');
            Route::post('invitations/{invitation}/resend', [SharedProjectController::class, 'resend'])->whereUuid('invitation')->middleware('throttle:10,1')->name('invitations.resend');
            Route::put('partners/{child}', [SharedProjectController::class, 'updateSubPartner'])->whereUuid('child')->name('partners.update');
        });
    });
});

/*
| Partner reports and project requests (ADR-0015), the same routes on both
| sides: projects/{project}/... for the owner, shared-projects/{participant}/...
| for a participant. Who may do what is checked in AppCollaboration on every
| request (the member's permission and, for a participant, its capability).
*/
$partnerWork = function () {
    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::post('reports', [ReportController::class, 'store'])->middleware('throttle:30,1')->name('reports.store');
    Route::post('reports/obligations', [ReportController::class, 'storeObligation'])->middleware('throttle:30,1')->name('reports.obligations.store');
    Route::post('reports/obligations/{obligation}/end', [ReportController::class, 'endObligation'])->whereUuid('obligation')->name('reports.obligations.end');
    Route::prefix('reports/{report}')->whereUuid('report')->name('reports.')->group(function () {
        Route::get('/', [ReportController::class, 'show'])->name('show');
        Route::put('/', [ReportController::class, 'update'])->name('update');
        Route::post('action', [ReportController::class, 'action'])->middleware('throttle:60,1')->name('action');
        Route::post('sources', [ReportController::class, 'addSource'])->name('sources.store');
        Route::delete('sources/{source}', [ReportController::class, 'removeSource'])->whereUuid('source')->name('sources.destroy');
        Route::post('attachments', [ReportController::class, 'attach'])->middleware('throttle:30,1')->name('attachments.store');
        Route::get('attachments/{attachment}', [ReportController::class, 'download'])->whereUuid('attachment')->name('attachments.show');
    });

    Route::get('requests', [RequestController::class, 'index'])->name('requests.index');
    Route::post('requests', [RequestController::class, 'store'])->middleware('throttle:30,1')->name('requests.store');
    Route::prefix('requests/{projectRequest}')->whereUuid('projectRequest')->name('requests.')->group(function () {
        Route::get('/', [RequestController::class, 'show'])->name('show');
        Route::put('/', [RequestController::class, 'update'])->name('update');
        Route::post('action', [RequestController::class, 'action'])->middleware('throttle:60,1')->name('action');
        Route::post('follow-up', [RequestController::class, 'followUp'])->name('follow-up');
        Route::post('attachments', [RequestController::class, 'attach'])->middleware('throttle:30,1')->name('attachments.store');
        Route::get('attachments/{attachment}', [RequestController::class, 'download'])->whereUuid('attachment')->name('attachments.show');
    });
};

Route::prefix('projects/{project}')->whereUuid('project')->name('projects.')->middleware('can:projects.view')->group($partnerWork);
Route::prefix('shared-projects/{participant}')->whereUuid('participant')->name('shared-projects.')->group($partnerWork);
