<?php

use App\Modules\Identity\Http\Controllers\OpenIdDiscoveryController;
use App\Modules\Identity\Http\Middleware\ValidateAuthorizationRequest;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Http\Controllers\AccessTokenController;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController;
use Laravel\Passport\Http\Controllers\AuthorizationController;
use Laravel\Passport\Http\Controllers\DenyAuthorizationController;
use OpenIDConnect\Laravel\JwksController;

/*
|--------------------------------------------------------------------------
| OpenID Provider (auth host only)
|--------------------------------------------------------------------------
|
| Loaded inside the auth host domain group (bootstrap/app.php). Passport's
| own route file is disabled so that no OAuth endpoint exists on any other
| host, and only the endpoints the platform uses are exposed: no device
| code flow and no JSON client/token management API.
|
*/

Route::get('.well-known/openid-configuration', OpenIdDiscoveryController::class)->name('openid.discovery');
Route::get('oauth/jwks', JwksController::class)->name('openid.jwks');

Route::post('oauth/token', [AccessTokenController::class, 'issueToken'])
    ->middleware('throttle:60,1')
    ->name('passport.token');

Route::middleware('web')->group(function () {
    Route::get('oauth/authorize', [AuthorizationController::class, 'authorize'])
        ->middleware(ValidateAuthorizationRequest::class)
        ->name('passport.authorizations.authorize');

    Route::middleware('auth')->group(function () {
        Route::post('oauth/authorize', [ApproveAuthorizationController::class, 'approve'])
            ->name('passport.authorizations.approve');
        Route::delete('oauth/authorize', [DenyAuthorizationController::class, 'deny'])
            ->name('passport.authorizations.deny');
    });
});
