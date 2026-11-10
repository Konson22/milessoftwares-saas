<?php

use App\Http\Controllers\Funding\MarketplaceController;
use App\Http\Middleware\RequireFunding;
use Illuminate\Support\Facades\Route;

/*
| The funding marketplace, for an organization that funds others: published
| profiles and listings of other organizations, read only through
| App\Support\MarketplaceView. An organization publishes its own from
| routes/web/publishing.php (public profile, funding listings).
*/

Route::prefix('marketplace')->name('marketplace.')->middleware([RequireFunding::class, 'can:grants.view'])->group(function () {
    Route::get('/', [MarketplaceController::class, 'organizations'])->name('organizations');
    Route::get('projects', [MarketplaceController::class, 'listings'])->name('projects');
    Route::get('projects/{listing}', [MarketplaceController::class, 'listing'])->whereUuid('listing')->name('projects.show');
    Route::get('organizations/{slug}', [MarketplaceController::class, 'organization'])->where('slug', '[a-z0-9-]+')->name('organizations.show');
    Route::get('organizations/{slug}/logo', [MarketplaceController::class, 'logo'])->where('slug', '[a-z0-9-]+')->name('organizations.logo');
    Route::get('documents/{document}', [MarketplaceController::class, 'document'])->whereUuid('document')->middleware('throttle:60,1')->name('documents.download');

    // Connections: interest in a listing (never a grant), and inviting to partner.
    Route::get('interests', [MarketplaceController::class, 'interests'])->name('interests');
    Route::post('projects/{listing}/interest', [MarketplaceController::class, 'expressInterest'])->whereUuid('listing')->middleware(['can:grants.manage', 'throttle:20,1'])->name('projects.interest');
    Route::middleware(['can:organization.manage', 'throttle:30,1'])->group(function () {
        Route::post('organizations/{slug}/invite', [MarketplaceController::class, 'invite'])->where('slug', '[a-z0-9-]+')->name('organizations.invite');
        Route::post('interests/{interest}/invite', [MarketplaceController::class, 'inviteFromInterest'])->whereUuid('interest')->name('interests.invite');
    });
});
