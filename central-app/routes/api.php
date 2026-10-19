<?php

use App\Sites\Central\Http\Controllers\Api\IntegrationController;
use App\Sites\Central\Http\Controllers\Api\ProvisioningCallbackController;
use App\Sites\Central\Http\Controllers\Api\SharedModulesController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Product API (auth host, /api/v1)
|--------------------------------------------------------------------------
|
| Stateless, for product backends holding a client-credentials token from
| /oauth/token. Every answer is limited to the calling application.
| Documented in docs/integration.md.
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

    Route::post('provisioning/steps/{step}/complete', [ProvisioningCallbackController::class, 'complete'])
        ->whereUuid('step')
        ->middleware('api.client:provisioning.write')
        ->name('provisioning.complete');
});
