<?php

use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Dashboard\OrganizationController;
use App\Http\Controllers\Dashboard\OrganizationSettingsController;
use App\Http\Controllers\Workspace\MemberController;
use App\Http\Controllers\Workspace\WorkspaceHomeController;
use App\Http\Controllers\WwwRedirectController;
use App\Modules\Identity\Http\Controllers\FirstPartySignInController;
use App\Modules\Identity\Http\Controllers\SignOutController;
use App\Support\Hosts\PlatformHosts;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Host-based routing
|--------------------------------------------------------------------------
|
| Every route is bound to a host. Requests for hosts not listed here (other
| domains, multi-level subdomains) match nothing and receive a 404.
| Fortify's routes are bound to the auth host via config/fortify.php.
|
| Order matters: the {tenant} wildcard is registered last so the fixed
| platform hosts always win.
|
*/

// milessoftwares.com — public marketing site
Route::domain(PlatformHosts::root())->group(function () {
    Route::inertia('/', 'marketing/home')->name('home');
});

// www.milessoftwares.com — permanent redirect to the apex, path preserved
Route::domain(PlatformHosts::www())->group(function () {
    Route::get('{path?}', WwwRedirectController::class)->where('path', '.*');
});

// auth.milessoftwares.com — identity: sign-in flows (Fortify) and account security
Route::domain(PlatformHosts::auth())->group(function () {
    // GET-only redirects: Route::redirect() also registers QUERY, which
    // Wayfinder's generated TypeScript does not support yet.
    Route::get('/', fn () => redirect('/settings/profile'));
    Route::get('signout', SignOutController::class)->name('signout');

    require __DIR__.'/settings.php';
});

// app. and admin. sign in through the auth host (OpenID Connect) and keep
// their own host-only sessions.
foreach (['app' => PlatformHosts::app(), 'admin' => PlatformHosts::admin()] as $client => $host) {
    Route::domain($host)->name("{$client}.")->group(function () use ($client) {
        Route::get('auth/login', [FirstPartySignInController::class, 'login'])->defaults('client', $client)->name('auth.login');
        Route::get('auth/callback', [FirstPartySignInController::class, 'callback'])->defaults('client', $client)->name('auth.callback');
        Route::post('logout', [FirstPartySignInController::class, 'logout'])->middleware('auth')->name('logout');
    });
}

// app.milessoftwares.com — customer dashboard. The current organization is
// re-validated on every request (ResolveCurrentOrganization).
Route::domain(PlatformHosts::app())->middleware(['auth', 'verified', 'current.organization'])->group(function () {
    Route::get('/', fn () => redirect('/dashboard'));
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::get('organizations/create', [OrganizationController::class, 'create'])->name('organizations.create');
    Route::post('organizations', [OrganizationController::class, 'store'])
        ->middleware('throttle:10,60')
        ->name('organizations.store');
    Route::get('organizations/slug-availability', [OrganizationController::class, 'slugAvailability'])
        ->middleware('throttle:30,1')
        ->name('organizations.slug-availability');
    Route::post('organizations/{organization}/switch', [OrganizationController::class, 'switch'])
        ->whereUuid('organization')
        ->name('organizations.switch');

    Route::get('organization/settings', [OrganizationSettingsController::class, 'edit'])->name('organization.settings');
    Route::put('organization/slug', [OrganizationSettingsController::class, 'updateSlug'])
        ->middleware('throttle:10,60')
        ->name('organization.slug.update');
});

// admin.milessoftwares.com — internal staff only (MFA enforced)
Route::domain(PlatformHosts::admin())->middleware(['auth', 'platform.staff'])->group(function () {
    Route::inertia('/', 'admin/overview')->name('admin.overview');
});

// {business}.milessoftwares.com — tenant workspace shell. Signs in through
// the auth host with the shared workspace client and keeps a host-only session.
Route::domain('{tenant}.'.PlatformHosts::root())
    ->where(['tenant' => '[a-z0-9-]+'])
    ->middleware('tenant')
    ->name('workspace.')
    ->group(function () {
        Route::get('auth/login', [FirstPartySignInController::class, 'login'])->defaults('client', 'workspace')->name('auth.login');
        Route::get('auth/callback', [FirstPartySignInController::class, 'callback'])->defaults('client', 'workspace')->name('auth.callback');
        Route::post('logout', [FirstPartySignInController::class, 'logout'])->middleware('auth')->name('logout');

        Route::middleware(['auth', 'workspace.access'])->group(function () {
            Route::get('/', WorkspaceHomeController::class)->name('home');
            Route::get('members', [MemberController::class, 'index'])->name('members.index');
            Route::get('members/{membership}', [MemberController::class, 'show'])
                ->whereUuid('membership')
                ->name('members.show');
        });
    });
