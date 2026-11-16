<?php

use App\Http\Controllers\Integration\ProjectsIntegrationController;
use App\Http\Middleware\EnterIntegration;
use Illuminate\Support\Facades\Route;
use Miles\PlatformClient\PlatformConfig;

/*
|--------------------------------------------------------------------------
| Read by Projects (ADR-0019 §20)
|--------------------------------------------------------------------------
|
| GET {workspace}/mel/integration/v1/…, server to server: no session, no
| CSRF. Projects proves itself with a platform-signed read token for M&E
| (scope integration.read), bound to one organization. Read only.
|
*/

if (! app(PlatformConfig::class)->configured()) {
    return;
}

Route::prefix(app(PlatformConfig::class)->path().'/integration/v1')
    ->middleware(['platform.integration:projects', EnterIntegration::class])
    ->name('integration.projects.')
    ->group(function () {
        Route::get('projects/{project}/verified-results', [ProjectsIntegrationController::class, 'verifiedResults'])->name('verified-results');
    });
