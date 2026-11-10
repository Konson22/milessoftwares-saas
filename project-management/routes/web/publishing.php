<?php

use App\Http\Controllers\Funding\DonorConnectionController;
use App\Http\Controllers\Funding\FundingInterestController;
use App\Http\Controllers\Funding\FundingListingController;
use App\Http\Controllers\Funding\PublicProfileController;
use Illuminate\Support\Facades\Route;

/*
| An organization's own side of the funding marketplace: its public profile
| and the projects it lists as seeking funding. Nothing here is public until
| published (marketplace.publish). Donors read it from routes/web/marketplace.php.
*/

Route::middleware('can:marketplace.publish')->group(function () {
    Route::get('public-profile', [PublicProfileController::class, 'edit'])->name('public-profile.edit');
    Route::put('public-profile', [PublicProfileController::class, 'update'])->name('public-profile.update');
    Route::post('public-profile/publish', [PublicProfileController::class, 'publish'])->name('public-profile.publish');
    Route::post('public-profile/unpublish', [PublicProfileController::class, 'unpublish'])->name('public-profile.unpublish');
    Route::post('public-profile/logo', [PublicProfileController::class, 'logo'])->middleware('throttle:20,1')->name('public-profile.logo');
    Route::get('public-profile/logo', [PublicProfileController::class, 'showLogo'])->name('public-profile.logo.show');

    Route::get('funding-listings', [FundingListingController::class, 'index'])->name('funding-listings.index');
    Route::post('funding-listings', [FundingListingController::class, 'store'])->name('funding-listings.store');
    Route::prefix('funding-listings/{listing}')->whereUuid('listing')->name('funding-listings.')->group(function () {
        Route::get('/', [FundingListingController::class, 'edit'])->name('edit');
        Route::put('/', [FundingListingController::class, 'update'])->name('update');
        Route::post('publish', [FundingListingController::class, 'publish'])->name('publish');
        Route::post('unpublish', [FundingListingController::class, 'unpublish'])->name('unpublish');
    });
});

// Expressions of interest donors sent about this organization's listings.
Route::middleware('can:marketplace.publish')->prefix('funding-interests')->name('funding-interests.')->group(function () {
    Route::get('/', [FundingInterestController::class, 'index'])->name('index');
    Route::post('{interest}/respond', [FundingInterestController::class, 'respond'])->whereUuid('interest')->middleware('throttle:30,1')->name('respond');
});

// Donors this organization is connected to, and their invitations to partner.
Route::middleware('can:organization.manage')->prefix('donor-connections')->name('donor-connections.')->group(function () {
    Route::get('/', [DonorConnectionController::class, 'index'])->name('index');
    Route::post('{partnership}/accept', [DonorConnectionController::class, 'accept'])->whereUuid('partnership')->middleware('throttle:30,1')->name('accept');
    Route::post('{partnership}/decline', [DonorConnectionController::class, 'decline'])->whereUuid('partnership')->middleware('throttle:30,1')->name('decline');
});
