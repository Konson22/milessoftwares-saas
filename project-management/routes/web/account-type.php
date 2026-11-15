<?php

use App\Http\Controllers\Admin\AccountTypeController;
use Illuminate\Support\Facades\Route;

/*
| Choosing what the organization uses Projects as, funder or implementer,
| before anything else (App\Http\Middleware\RequireAccountType).
*/

Route::get('account-type', [AccountTypeController::class, 'show'])->name('account-type.show');
Route::post('account-type', [AccountTypeController::class, 'store'])->can('organization.manage')->name('account-type.store');
