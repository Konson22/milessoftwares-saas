<?php

use App\Http\Controllers\Organizations\PartnershipController;
use App\Http\Middleware\ConfineFunderContacts;
use App\Http\Middleware\EnterWorkspace;
use App\Http\Middleware\RememberPerson;
use App\Http\Middleware\RequireFunding;
use App\Http\Middleware\RequireModule;
use App\Support\Modules;
use Illuminate\Support\Facades\Route;
use Miles\PlatformClient\PlatformConfig;

/*
|--------------------------------------------------------------------------
| Web routes, one file per domain (routes/web/*.php)
|--------------------------------------------------------------------------
|
| Everything is in an organization's workspace on the platform,
| {slug}.<root domain>/projects. Sign-in, sign-out, provisioning and webhooks
| are miles/platform-client's (/projects/auth/..., platform.*).
|
| "platform" finds the organization from the host and requires a member the
| platform lets into Projects; EnterWorkspace then binds the organization and
| the member's access here. Every route is checked again against the
| member's permissions (platform/catalog.json).
|
*/

Route::prefix(app(PlatformConfig::class)->path())->middleware('web')->group(function () {
    // The link in a donor's invitation, opened on the donor's workspace by
    // someone who is not a member there: on to their own workspace.
    Route::middleware('platform.tenant')->group(function () {
        Route::get('invitations/{token}', [PartnershipController::class, 'landing'])->where('token', '[A-Za-z0-9]{48}')->name('partnerships.landing');
        Route::post('invitations/{token}', [PartnershipController::class, 'open'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:20,1')->name('partnerships.open');
    });

    // Following the organizations it funds: only for one that funds others.
    Route::middleware(['platform', EnterWorkspace::class, RequireFunding::class])
        ->group(function () {
            require __DIR__.'/web/beneficiaries.php';
            require __DIR__.'/web/marketplace.php';
        });

    Route::middleware(['platform', EnterWorkspace::class, ConfineFunderContacts::class, RememberPerson::class])
        ->group(function () {
            require __DIR__.'/web/partnerships.php';
            require __DIR__.'/web/projects.php';
            require __DIR__.'/web/structure.php';
            require __DIR__.'/web/people.php';
            require __DIR__.'/web/publishing.php';
            Route::middleware(RequireModule::class.':'.Modules::GRANTS)->group(function () {
                require __DIR__.'/web/funding.php';
                require __DIR__.'/web/donor.php';
            });
            Route::middleware(RequireModule::class.':'.Modules::DOCUMENTS)->group(__DIR__.'/web/documents.php');
            Route::middleware(RequireModule::class.':'.Modules::PROCUREMENT)->group(__DIR__.'/web/procurement.php');
            require __DIR__.'/web/approvals.php';
            require __DIR__.'/web/admin.php';
            Route::prefix('advances')->name('advances.')->middleware(RequireModule::class.':'.Modules::ACCOUNTING)
                ->group(__DIR__.'/web/advances.php');
            Route::prefix('petty-cash')->name('petty-cash.')->middleware(RequireModule::class.':'.Modules::ACCOUNTING)
                ->group(__DIR__.'/web/petty-cash.php');
        });
});
