<?php

use App\Http\Controllers\Api\IntegrationController;
use App\Http\Controllers\Api\ProvisioningCallbackController;
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
        Route::get('organizations/{organization}/members/{subject}/effective-access', [IntegrationController::class, 'effectiveAccess'])
            ->whereUuid('organization')
            ->name('effective-access');
    });

    Route::post('provisioning/steps/{step}/complete', [ProvisioningCallbackController::class, 'complete'])
        ->whereUuid('step')
        ->middleware('api.client:provisioning.write')
        ->name('provisioning.complete');
});
