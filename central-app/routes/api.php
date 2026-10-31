<?php

use App\Sites\Central\Http\Controllers\Api\AppInvitationController;
use App\Sites\Central\Http\Controllers\Api\AppOrganizationController;
use App\Sites\Central\Http\Controllers\Api\IntegrationController;
use App\Sites\Central\Http\Controllers\Api\LicenceActivationController;
use App\Sites\Central\Http\Controllers\Api\ProvisioningCallbackController;
use App\Sites\Central\Http\Controllers\Api\SharedModulesController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Product API (auth host, /api/v1)
|--------------------------------------------------------------------------
|
| Stateless. Product backends hold a client-credentials token from
| /oauth/token; every answer is limited to the calling application. An
| application's own app (api.user) holds a person's token from its public
| client. Documented in docs/integration.md.
|
*/

Route::prefix('api/v1')->middleware('throttle:600,1')->name('api.')->group(function () {
    Route::middleware('api.client:access.read')->group(function () {
        Route::get('organizations/{organization}', [IntegrationController::class, 'organization'])
            ->where('organization', '[A-Za-z0-9-]+')
            ->name('organizations.show');
        Route::get('organizations/{organization}/branches', [IntegrationController::class, 'branches'])
            ->whereUuid('organization')
            ->name('organizations.branches');
        Route::get('organizations/{organization}/departments', [IntegrationController::class, 'departments'])
            ->whereUuid('organization')
            ->name('organizations.departments');
        Route::get('organizations/{organization}/positions', [IntegrationController::class, 'positions'])
            ->whereUuid('organization')
            ->name('organizations.positions');
        Route::get('organizations/{organization}/members/{subject}/effective-access', [IntegrationController::class, 'effectiveAccess'])
            ->whereUuid('organization')
            ->name('effective-access');
    });

    // Shared modules: organization-bound event tokens, and health reports.
    Route::post('event-tokens', [SharedModulesController::class, 'eventToken'])
        ->middleware('api.client:events.issue')
        ->name('event-tokens.store');
    Route::post('health', [SharedModulesController::class, 'health'])
        ->middleware('api.client:health.write')
        ->name('health.store');

    // A person in an application's own app (desktop counter): a new business.
    Route::middleware('api.user')->prefix('account')->name('account.')->group(function () {
        Route::get('organizations', [AppOrganizationController::class, 'index'])->name('organizations.index');
        Route::get('organizations/slug-availability', [AppOrganizationController::class, 'slugAvailability'])
            ->middleware('throttle:30,1')
            ->name('organizations.slug-availability');
        Route::post('organizations', [AppOrganizationController::class, 'store'])
            ->middleware('throttle:10,60')
            ->name('organizations.store');

        // Its staff, invited from the app (mobile counter).
        Route::prefix('organizations/{organization}/invitations')
            ->where(['organization' => '[a-z0-9-]+'])
            ->name('invitations.')
            ->group(function () {
                Route::get('/', [AppInvitationController::class, 'index'])->name('index');
                Route::post('/', [AppInvitationController::class, 'store'])->middleware('throttle:30,60')->name('store');
                Route::post('{invitation}/resend', [AppInvitationController::class, 'resend'])
                    ->whereUuid('invitation')->middleware('throttle:10,60')->name('resend');
                Route::delete('{invitation}', [AppInvitationController::class, 'destroy'])->whereUuid('invitation')->name('destroy');
            });
    });

    // The offline desktop pharmacy activating with a licence key (no sign-in; the key is the proof).
    Route::post('licences/activate', [LicenceActivationController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('licences.activate');

    Route::post('provisioning/steps/{step}/complete', [ProvisioningCallbackController::class, 'complete'])
        ->whereUuid('step')
        ->middleware('api.client:provisioning.write')
        ->name('provisioning.complete');
});
