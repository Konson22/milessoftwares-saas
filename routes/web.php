<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Dashboard\InvitationAcceptanceController;
use App\Http\Controllers\Dashboard\OrganizationController;
use App\Http\Controllers\Dashboard\OrganizationSettingsController;
use App\Http\Controllers\Marketing;
use App\Http\Controllers\Workspace\AccessController;
use App\Http\Controllers\Workspace\InvitationController;
use App\Http\Controllers\Workspace\MemberController;
use App\Http\Controllers\Workspace\StructureController;
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
    Route::get('/', [Marketing\PageController::class, 'home'])->name('home');

    Route::name('marketing.')->group(function () {
        Route::get('products/{code}', [Marketing\PageController::class, 'product'])->where('code', '[a-z][a-z0-9_]*')->name('product');
        Route::get('pricing', [Marketing\PageController::class, 'pricing'])->name('pricing');

        // Public forms: rate limited per IP in front of the spam checks.
        Route::get('demo', [Marketing\LeadController::class, 'demoForm'])->name('demo');
        Route::post('demo', [Marketing\LeadController::class, 'storeDemo'])->middleware('throttle:5,60')->name('demo.store');
        Route::get('contact', [Marketing\LeadController::class, 'contactForm'])->name('contact');
        Route::post('contact', [Marketing\LeadController::class, 'storeContact'])->middleware('throttle:5,60')->name('contact.store');
        Route::inertia('thanks', 'marketing/thanks')->name('thanks');

        Route::get('sitemap.xml', [Marketing\SeoController::class, 'sitemap'])->name('sitemap');
        Route::get('robots.txt', [Marketing\SeoController::class, 'robots'])->name('robots');
    });
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

    // Invitation links: the token is the credential, so it is rate limited.
    Route::get('invitations/{token}', [InvitationAcceptanceController::class, 'show'])
        ->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:30,1')->name('invitations.show');
    Route::post('invitations/{token}/accept', [InvitationAcceptanceController::class, 'accept'])
        ->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:10,1')->name('invitations.accept');

    Route::get('organization/settings', [OrganizationSettingsController::class, 'edit'])->name('organization.settings');
    Route::put('organization/details', [OrganizationSettingsController::class, 'updateDetails'])->name('organization.details.update');
    Route::get('organization/subscription', [OrganizationSettingsController::class, 'subscription'])->name('organization.subscription');
    Route::put('organization/slug', [OrganizationSettingsController::class, 'updateSlug'])
        ->middleware('throttle:10,60')
        ->name('organization.slug.update');
});

// admin.milessoftwares.com — internal staff only: MFA, fresh sign-in per
// session, short idle timeout, and a platform permission per area.
Route::domain(PlatformHosts::admin())
    ->middleware(['auth', 'platform.staff', 'admin.idle'])
    ->name('admin.')
    ->group(function () {
        Route::get('/', Admin\OverviewController::class)->name('overview');

        Route::middleware('staff.can:organizations.view')->group(function () {
            Route::get('organizations', [Admin\OrganizationController::class, 'index'])->name('organizations.index');
            Route::get('organizations/{organization}', [Admin\OrganizationController::class, 'show'])->whereUuid('organization')->name('organizations.show');
        });
        Route::put('organizations/{organization}/status', [Admin\OrganizationController::class, 'updateStatus'])
            ->whereUuid('organization')
            ->middleware('staff.can:organizations.suspend')
            ->name('organizations.status');

        // Support access: request, approve (by someone else), revoke, and the
        // customer data views that need a grant in effect.
        Route::middleware('staff.can:support.request')->group(function () {
            Route::get('support-access', [Admin\SupportAccessController::class, 'index'])->name('support.index');
            Route::post('organizations/{organization}/support-access', [Admin\SupportAccessController::class, 'store'])
                ->whereUuid('organization')->middleware('throttle:20,60')->name('support.store');
            Route::post('support-access/{grant}/revoke', [Admin\SupportAccessController::class, 'revoke'])->whereUuid('grant')->name('support.revoke');
            Route::get('organizations/{organization}/customer/members', [Admin\CustomerDataController::class, 'members'])
                ->whereUuid('organization')->name('customer.members');
            Route::get('organizations/{organization}/customer/structure', [Admin\CustomerDataController::class, 'structure'])
                ->whereUuid('organization')->name('customer.structure');
        });
        Route::post('support-access/{grant}/approve', [Admin\SupportAccessController::class, 'approve'])
            ->whereUuid('grant')
            ->middleware('staff.can:support.approve')
            ->name('support.approve');

        Route::middleware('staff.can:registry.manage')->group(function () {
            Route::get('applications', [Admin\ApplicationController::class, 'index'])->name('applications.index');
            Route::put('applications/{application}', [Admin\ApplicationController::class, 'update'])->whereUuid('application')->name('applications.update');
            Route::post('applications/catalogs', [Admin\ApplicationController::class, 'importCatalog'])->name('applications.catalogs.store');
        });

        Route::middleware('staff.can:plans.manage')->group(function () {
            Route::get('plans', [Admin\PlanController::class, 'index'])->name('plans.index');
            Route::post('plans', [Admin\PlanController::class, 'store'])->name('plans.store');
            Route::put('plans/{plan}', [Admin\PlanController::class, 'update'])->whereUuid('plan')->name('plans.update');
        });

        Route::middleware('staff.can:entitlements.manage')->prefix('organizations/{organization}')->whereUuid('organization')->group(function () {
            Route::post('subscriptions', [Admin\EntitlementController::class, 'assignPlan'])->name('subscriptions.store');
            Route::post('subscriptions/{subscription}/cancel', [Admin\EntitlementController::class, 'cancelSubscription'])->whereUuid('subscription')->name('subscriptions.cancel');
            Route::post('entitlements', [Admin\EntitlementController::class, 'grant'])->name('entitlements.store');
            Route::post('entitlements/{entitlement}/revoke', [Admin\EntitlementController::class, 'revoke'])->whereUuid('entitlement')->name('entitlements.revoke');
        });

        Route::middleware('staff.can:security.view')->group(function () {
            Route::get('security', [Admin\SecurityController::class, 'index'])->name('security.index');
            Route::post('security/verify-chain', [Admin\SecurityController::class, 'verifyChain'])->middleware('throttle:6,1')->name('security.verify-chain');
        });

        Route::middleware('staff.can:leads.manage')->group(function () {
            Route::get('leads', [Admin\LeadController::class, 'index'])->name('leads.index');
            Route::put('leads/{type}/{lead}', [Admin\LeadController::class, 'update'])
                ->whereIn('type', ['demo', 'contact'])->whereUuid('lead')->name('leads.update');
        });

        Route::middleware('staff.can:staff.manage')->prefix('staff')->name('staff.')->group(function () {
            Route::get('/', [Admin\StaffController::class, 'index'])->name('index');
            Route::post('/', [Admin\StaffController::class, 'store'])->name('store');
            Route::post('{staff}/roles', [Admin\StaffController::class, 'grantRole'])->whereUuid('staff')->name('roles.store');
            Route::delete('{staff}/roles/{role}', [Admin\StaffController::class, 'revokeRole'])->whereUuid('staff')->name('roles.destroy');
            Route::put('{staff}/status', [Admin\StaffController::class, 'updateStatus'])->whereUuid('staff')->name('status');
        });
    });

// Signed-in hosts are never indexed.
foreach ([PlatformHosts::auth(), PlatformHosts::app(), PlatformHosts::admin()] as $host) {
    Route::domain($host)->get('robots.txt', [Marketing\SeoController::class, 'disallowAll']);
}

// {business}.milessoftwares.com — tenant workspace shell. Signs in through
// the auth host with the shared workspace client and keeps a host-only session.
Route::domain('{tenant}.'.PlatformHosts::root())
    ->where(['tenant' => '[a-z0-9-]+'])
    ->middleware('tenant')
    ->name('workspace.')
    ->group(function () {
        Route::get('robots.txt', [Marketing\SeoController::class, 'disallowAll']);
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
