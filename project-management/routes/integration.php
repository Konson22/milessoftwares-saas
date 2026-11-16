<?php

use App\Http\Controllers\Integration\MelIntegrationController;
use App\Http\Middleware\EnterIntegration;
use Illuminate\Support\Facades\Route;
use Miles\PlatformClient\PlatformConfig;

/*
|--------------------------------------------------------------------------
| Read by M&E (ADR-0019 §20)
|--------------------------------------------------------------------------
|
| GET {workspace}/projects/integration/v1/…, server to server: no session,
| no CSRF. M&E proves itself with a platform-signed read token for
| Projects (scope integration.read), bound to one organization and to the
| member it acts for. Read only.
|
*/

if (! app(PlatformConfig::class)->configured()) {
    return;
}

Route::prefix(app(PlatformConfig::class)->path().'/integration/v1')
    ->middleware(['platform.integration:mel', EnterIntegration::class])
    ->name('integration.mel.')
    ->group(function () {
        Route::get('projects', [MelIntegrationController::class, 'projects'])->name('projects');
        Route::get('projects/{project}', [MelIntegrationController::class, 'project'])->name('project');
        Route::get('milestones', [MelIntegrationController::class, 'milestones'])->name('milestones');
    });
