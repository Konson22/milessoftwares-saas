<?php

use App\Http\Controllers\FinanceEventController;
use Illuminate\Support\Facades\Route;
use Miles\PlatformClient\PlatformConfig;

/*
|--------------------------------------------------------------------------
| Outcomes from Finance (Phase 3B)
|--------------------------------------------------------------------------
|
| POST {workspace}/projects/events, server to server: no session, no CSRF.
| Finance proves itself with a platform-signed event token for Projects,
| bound to one organization (docs/finance/finance-projects-contract.md).
|
*/

if (! app(PlatformConfig::class)->configured()) {
    return;
}

Route::prefix(app(PlatformConfig::class)->path())
    ->post('events', FinanceEventController::class)
    ->name('finance.events.store');
