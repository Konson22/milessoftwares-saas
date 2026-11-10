<?php

use App\Http\Controllers\Organizations\PartnershipController;
use Illuminate\Support\Facades\Route;

/*
| A donor's invitation to an organization it funds, accepted in the invited
| organization's own workspace by someone who manages it there. The link in
| the email opens on the donor's workspace (PartnershipController::landing,
| routes/web.php), which sends them on to theirs.
*/

Route::middleware('can:organization.manage')->group(function () {
    Route::get('partnerships/{token}', [PartnershipController::class, 'show'])->where('token', '[A-Za-z0-9]{48}')->name('partnerships.show');
    Route::post('partnerships/{token}', [PartnershipController::class, 'accept'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:10,1')->name('partnerships.accept');
    Route::post('partnerships/{token}/decline', [PartnershipController::class, 'decline'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:10,1')->name('partnerships.decline');
});
