<?php

use App\Sites\Admin\Http\Controllers as Admin;
use App\Sites\Public\Http\Controllers\SeoController;
use App\Support\Hosts\PlatformHosts;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin site
|--------------------------------------------------------------------------
|
| admin.milesoftwares.com — internal staff only. Pages live in
| resources/js/sites/admin.
|
*/

// Its own sign-in on its own host (password, then the two-factor code) and a
// host-only session: being signed in to the customer apps opens nothing here.
Route::domain(PlatformHosts::admin())->name('admin.')->group(function () {
    Route::get('login', [Admin\AdminLoginController::class, 'create'])->name('login');
    Route::post('login', [Admin\AdminLoginController::class, 'store'])->middleware('throttle:login')->name('login.store');
    Route::get('two-factor', [Admin\AdminLoginController::class, 'challenge'])->name('two-factor');
    Route::post('two-factor', [Admin\AdminLoginController::class, 'verify'])->middleware('throttle:5,1')->name('two-factor.store');
    Route::post('logout', [Admin\AdminLoginController::class, 'destroy'])->middleware('auth')->name('logout');
    // Old links to the auth-host sign-in. GET-only (Route::redirect() also
    // registers QUERY, which Wayfinder's generated TypeScript cannot type).
    Route::get('auth/login', fn () => redirect('/login'));
});

// Signed-in hosts are never indexed.
Route::domain(PlatformHosts::admin())->get('robots.txt', [SeoController::class, 'disallowAll']);

// MFA, fresh sign-in per session, short idle timeout, and a platform
// permission per area.
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

            // Integration: clients, webhooks, provisioning.
            Route::prefix('applications/{application}/integration')->whereUuid('application')->name('integration.')->group(function () {
                Route::get('/', [Admin\IntegrationController::class, 'show'])->name('show');
                Route::post('sign-in-clients', [Admin\IntegrationController::class, 'storeSignInClient'])->name('sign-in-clients.store');
                Route::post('service-clients', [Admin\IntegrationController::class, 'storeServiceClient'])->name('service-clients.store');
                Route::post('clients/{client}/rotate', [Admin\IntegrationController::class, 'rotateSecret'])->whereUuid('client')->name('clients.rotate');
                Route::post('clients/{client}/disable', [Admin\IntegrationController::class, 'disableClient'])->whereUuid('client')->name('clients.disable');
                Route::put('webhook', [Admin\IntegrationController::class, 'updateWebhook'])->name('webhook.update');
                Route::post('deliveries/{delivery}/redeliver', [Admin\IntegrationController::class, 'redeliver'])->whereUuid('delivery')->name('deliveries.redeliver');
                Route::put('provisioning', [Admin\IntegrationController::class, 'updateProvisioning'])->name('provisioning.update');
                Route::post('provisioning/{step}/retry', [Admin\IntegrationController::class, 'retryProvisioning'])->whereUuid('step')->name('provisioning.retry');
            });
        });

        Route::middleware('staff.can:plans.manage')->group(function () {
            Route::get('plans', [Admin\PlanController::class, 'index'])->name('plans.index');
            Route::get('products', [Admin\ProductController::class, 'index'])->name('products.index');
            Route::post('plans', [Admin\PlanController::class, 'store'])->name('plans.store');
            Route::put('plans/{plan}', [Admin\PlanController::class, 'update'])->whereUuid('plan')->name('plans.update');
        });

        Route::middleware('staff.can:entitlements.manage')->prefix('organizations/{organization}')->whereUuid('organization')->group(function () {
            Route::post('subscriptions', [Admin\EntitlementController::class, 'assignPlan'])->name('subscriptions.store');
            Route::post('subscriptions/{subscription}/cancel', [Admin\EntitlementController::class, 'cancelSubscription'])->whereUuid('subscription')->name('subscriptions.cancel');
            Route::post('entitlements', [Admin\EntitlementController::class, 'grant'])->name('entitlements.store');
            Route::post('entitlements/{entitlement}/revoke', [Admin\EntitlementController::class, 'revoke'])->whereUuid('entitlement')->name('entitlements.revoke');
            Route::post('features', [Admin\EntitlementController::class, 'feature'])->name('features.store');

            // Customers' plan requests: approving assigns or cancels the plan.
            Route::post('plan-requests/{planRequest}/approve', [Admin\BillingController::class, 'approveRequest'])->whereUuid('planRequest')->name('plan-requests.approve');
            Route::post('plan-requests/{planRequest}/decline', [Admin\BillingController::class, 'declineRequest'])->whereUuid('planRequest')->name('plan-requests.decline');
        });

        // Manual billing: invoices, and payments made outside the platform.
        Route::middleware('staff.can:billing.manage')->group(function () {
            Route::get('billing', [Admin\BillingController::class, 'index'])->name('billing.index');

            Route::prefix('organizations/{organization}')->whereUuid('organization')->group(function () {
                Route::post('invoices', [Admin\BillingController::class, 'issueInvoice'])->name('invoices.store');
                Route::post('invoices/{invoice}/void', [Admin\BillingController::class, 'voidInvoice'])->whereUuid('invoice')->name('invoices.void');
                Route::post('invoices/{invoice}/payments', [Admin\BillingController::class, 'recordPayment'])->whereUuid('invoice')->name('payments.store');
                Route::post('payments/{payment}/confirm', [Admin\BillingController::class, 'confirmPayment'])->whereUuid('payment')->name('payments.confirm');
                Route::post('payments/{payment}/reject', [Admin\BillingController::class, 'rejectPayment'])->whereUuid('payment')->name('payments.reject');
            });
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
