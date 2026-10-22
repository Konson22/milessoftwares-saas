<?php

use App\Http\Controllers\FallbackController;
use App\Modules\Identity\Http\Controllers\FirstPartySignInController;
use App\Modules\Identity\Http\Controllers\GoogleSignInController;
use App\Modules\Identity\Http\Controllers\SignOutController;
use App\Sites\Central\Http\Controllers\Account\JoinController;
use App\Sites\Central\Http\Controllers\Account\PasswordSetupController;
use App\Sites\Central\Http\Controllers\Dashboard\BillingController;
use App\Sites\Central\Http\Controllers\Dashboard\DashboardController;
use App\Sites\Central\Http\Controllers\Dashboard\ExploreController;
use App\Sites\Central\Http\Controllers\Dashboard\InvitationAcceptanceController;
use App\Sites\Central\Http\Controllers\Dashboard\OrganizationController;
use App\Sites\Central\Http\Controllers\Dashboard\OrganizationSettingsController;
use App\Sites\Central\Http\Controllers\Dashboard\SubscriptionController;
use App\Sites\Central\Http\Controllers\Dashboard\TrialController;
use App\Sites\Central\Http\Controllers\Workspace\AccessController;
use App\Sites\Central\Http\Controllers\Workspace\InvitationController;
use App\Sites\Central\Http\Controllers\Workspace\MemberAccountController;
use App\Sites\Central\Http\Controllers\Workspace\MemberController;
use App\Sites\Central\Http\Controllers\Workspace\StructureController;
use App\Sites\Central\Http\Controllers\Workspace\WorkspaceHomeController;
use App\Sites\Public\Http\Controllers\SeoController;
use App\Support\Hosts\PlatformHosts;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Central app
|--------------------------------------------------------------------------
|
| auth., app. and every {business} workspace host. Pages live in
| resources/js/sites/central. The auth host's OAuth and API routes are in
| oauth.php and api.php; Fortify's routes are bound to the auth host via
| config/fortify.php.
|
| Every route is bound to a host. Requests for hosts not listed here (other
| domains, multi-level subdomains) match nothing and receive a 404.
|
| Order matters: this file loads after public.php and admin.php, and the
| {tenant} wildcard comes last in it, so the fixed platform hosts always win.
|
*/

// auth.milesoftwares.com — identity: sign-in flows (Fortify) and account security
Route::domain(PlatformHosts::auth())->group(function () {
    // GET-only redirects: Route::redirect() also registers QUERY, which
    // Wayfinder's generated TypeScript does not support yet.
    Route::get('/', fn () => redirect('/settings/profile'));
    Route::get('signout', SignOutController::class)->name('signout');

    // Sign in with Google (not configured: 404).
    Route::middleware(['guest:'.config('fortify.guard'), 'throttle:20,1'])->group(function () {
        Route::get('auth/google', [GoogleSignInController::class, 'redirect'])->name('google.redirect');
        Route::get('auth/google/callback', [GoogleSignInController::class, 'callback'])->name('google.callback');
    });

    // First sign-in with a temporary password: choose your own.
    Route::middleware('auth')->group(function () {
        Route::get('password/setup', [PasswordSetupController::class, 'show'])->name('password.setup');
        Route::post('password/setup', [PasswordSetupController::class, 'store'])->middleware('throttle:10,1')->name('password.setup.store');
    });

    // Invitation links: new people create their account here, with the
    // token as proof of their email address.
    Route::get('join/{token}', [JoinController::class, 'show'])
        ->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:30,1')->name('join.show');
    Route::post('join/{token}', [JoinController::class, 'store'])
        ->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:10,1')->name('join.store');

    require __DIR__.'/settings.php';
});

// app. signs in through the auth host (OpenID Connect) and keeps its own
// host-only session.
Route::domain(PlatformHosts::app())->name('app.')->group(function () {
    Route::get('auth/login', [FirstPartySignInController::class, 'login'])->defaults('client', 'app')->name('auth.login');
    Route::get('auth/callback', [FirstPartySignInController::class, 'callback'])->defaults('client', 'app')->name('auth.callback');
    Route::post('logout', [FirstPartySignInController::class, 'logout'])->middleware('auth')->name('logout');
});

// app.milesoftwares.com — customer dashboard. The current organization is
// re-validated on every request (ResolveCurrentOrganization).
Route::domain(PlatformHosts::app())->middleware(['auth', 'verified', 'current.organization'])->group(function () {
    Route::get('/', fn () => redirect('/dashboard'));
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('explore', ExploreController::class)->name('explore');

    // Free trials for a business that already exists (owner only).
    Route::get('trial', [TrialController::class, 'show'])->name('trial');
    Route::post('trial', [TrialController::class, 'store'])->middleware('throttle:10,60')->name('trial.store');

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

    // Invitation links: the token is the credential, so it is rate limited.
    Route::get('invitations/{token}', [InvitationAcceptanceController::class, 'show'])
        ->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:30,1')->name('invitations.show');
    Route::post('invitations/{token}/accept', [InvitationAcceptanceController::class, 'accept'])
        ->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:10,1')->name('invitations.accept');

    Route::get('organization/settings', [OrganizationSettingsController::class, 'edit'])->name('organization.settings');
    Route::put('organization/details', [OrganizationSettingsController::class, 'updateDetails'])->name('organization.details.update');
    Route::put('organization/billing-details', [OrganizationSettingsController::class, 'updateBilling'])->name('organization.billing-details.update');
    Route::put('organization/slug', [OrganizationSettingsController::class, 'updateSlug'])
        ->middleware('throttle:10,60')
        ->name('organization.slug.update');

    // Plans and subscriptions: changes are requests that staff decide.
    Route::get('organization/subscription', [SubscriptionController::class, 'show'])->name('organization.subscription');
    Route::get('organization/plans', [SubscriptionController::class, 'plans'])->name('organization.plans');
    Route::post('organization/plan-requests', [SubscriptionController::class, 'requestPlan'])
        ->middleware('throttle:20,60')
        ->name('organization.plan-requests.store');
    Route::post('organization/plan-requests/{planRequest}/withdraw', [SubscriptionController::class, 'withdraw'])
        ->whereUuid('planRequest')
        ->name('organization.plan-requests.withdraw');
    Route::post('organization/subscriptions/{subscription}/cancellation', [SubscriptionController::class, 'requestCancellation'])
        ->whereUuid('subscription')
        ->middleware('throttle:20,60')
        ->name('organization.subscriptions.cancellation');

    // Billing: invoices issued by staff, payments made offline and reported here.
    Route::get('organization/billing', [BillingController::class, 'index'])->name('organization.billing');
    Route::get('organization/billing/invoices/{invoice}', [BillingController::class, 'invoice'])
        ->whereUuid('invoice')
        ->name('organization.invoices.show');
    Route::post('organization/billing/invoices/{invoice}/payments', [BillingController::class, 'reportPayment'])
        ->whereUuid('invoice')
        ->middleware('throttle:20,60')
        ->name('organization.invoices.payments.store');
});

// Signed-in hosts are never indexed.
foreach ([PlatformHosts::auth(), PlatformHosts::app()] as $host) {
    Route::domain($host)->get('robots.txt', [SeoController::class, 'disallowAll']);
}

// {business}.milesoftwares.com — tenant workspace shell. Signs in through
// the auth host with the shared workspace client and keeps a host-only session.
Route::domain('{tenant}.'.PlatformHosts::root())
    ->where(['tenant' => '[a-z0-9-]+'])
    ->middleware('tenant')
    ->name('workspace.')
    ->group(function () {
        Route::get('robots.txt', [SeoController::class, 'disallowAll']);
        Route::get('auth/login', [FirstPartySignInController::class, 'login'])->defaults('client', 'workspace')->name('auth.login');
        Route::get('auth/callback', [FirstPartySignInController::class, 'callback'])->defaults('client', 'workspace')->name('auth.callback');
        Route::post('logout', [FirstPartySignInController::class, 'logout'])->middleware('auth')->name('logout');

        Route::middleware(['auth', 'workspace.access'])->group(function () {
            Route::get('/', WorkspaceHomeController::class)->name('home');
            Route::get('members', [MemberController::class, 'index'])->name('members.index');
            Route::get('members/{membership}', [MemberController::class, 'show'])
                ->whereUuid('membership')
                ->name('members.show');

            // Administration. Bound models resolve through the tenant scope, so
            // ids from another organization 404.
            Route::prefix('manage')->name('manage.')->group(function () {
                Route::get('structure', [StructureController::class, 'index'])->name('structure');
                Route::post('branches', [StructureController::class, 'storeBranch'])->name('branches.store');
                Route::put('branches/{branch}', [StructureController::class, 'updateBranch'])->whereUuid('branch')->name('branches.update');
                Route::post('departments', [StructureController::class, 'storeDepartment'])->name('departments.store');
                Route::put('departments/{department}', [StructureController::class, 'updateDepartment'])->whereUuid('department')->name('departments.update');
                Route::post('positions', [StructureController::class, 'storePosition'])->name('positions.store');
                Route::put('positions/{position}', [StructureController::class, 'updatePosition'])->whereUuid('position')->name('positions.update');

                Route::put('members/{membership}/status', [MemberController::class, 'updateStatus'])->whereUuid('membership')->name('members.status');
                // Adding someone directly with a temporary password.
                Route::post('members', [MemberAccountController::class, 'store'])->middleware('throttle:30,60')->name('members.store');
                Route::post('members/{membership}/temporary-password', [MemberAccountController::class, 'reissue'])
                    ->whereUuid('membership')->middleware('throttle:10,60')->name('members.temporary-password');

                Route::get('invitations', [InvitationController::class, 'index'])->name('invitations');
                Route::post('invitations', [InvitationController::class, 'store'])->middleware('throttle:30,60')->name('invitations.store');
                Route::post('invitations/{invitation}/resend', [InvitationController::class, 'resend'])->whereUuid('invitation')->middleware('throttle:10,60')->name('invitations.resend');
                Route::delete('invitations/{invitation}', [InvitationController::class, 'destroy'])->whereUuid('invitation')->name('invitations.destroy');

                Route::get('access', [AccessController::class, 'index'])->name('access');
                Route::post('access', [AccessController::class, 'store'])->middleware('throttle:30,1')->name('access.store');
                Route::delete('access/{assignment}', [AccessController::class, 'destroy'])->whereUuid('assignment')->name('access.destroy');
            });
        });
    });

// Unknown addresses on any host: answered inside the web middleware, so the
// error page knows whether the visitor is signed in. Every method, and no
// CSRF check: the answer is always a 404 (or 405, see FallbackController).
// Not Route::any(): that adds QUERY, which Wayfinder's TypeScript cannot type.
Route::match(['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], '{fallbackPlaceholder}', FallbackController::class)
    ->where('fallbackPlaceholder', '.*')
    ->fallback()
    ->withoutMiddleware(PreventRequestForgery::class);
