<?php

use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RequisitionController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SourcingController;
use App\Http\Controllers\SupplierController;
use App\Http\Middleware\RememberMember;
use Illuminate\Support\Facades\Route;
use Miles\PlatformClient\Money\EntryCurrencyController;
use Miles\PlatformClient\PlatformConfig;

/*
|--------------------------------------------------------------------------
| Tatook Procurement (ADR-0023)
|--------------------------------------------------------------------------
|
| Served at {workspace}/procurement. The "platform" middleware resolves the
| workspace from the host and requires a signed-in member whose platform
| access allows Procurement (an unentitled workspace answers 404). Each
| route checks its catalog permission; the domain (App\Procurement) checks
| state, separation of duties and what the member reaches (404 outside).
|
*/

// Not connected yet (fresh checkout, no .env): boot without product routes.
if (! app(PlatformConfig::class)->configured()) {
    return;
}

Route::prefix(app(PlatformConfig::class)->path())
    ->middleware(['web', 'platform', RememberMember::class])
    ->group(function () {
        Route::get('/', HomeController::class)->name('home');
        Route::get('approvals', ApprovalController::class)->name('approvals.index');

        // Requisitions: requesters reach their own (App\Procurement\Access).
        Route::get('requisitions', [RequisitionController::class, 'index'])->name('requisitions.index');
        Route::get('requisitions/{requisition}', [RequisitionController::class, 'show'])->whereUuid('requisition')->name('requisitions.show');
        Route::middleware('can:procurement.requisitions.create')->group(function () {
            Route::get('requisitions/create', [RequisitionController::class, 'create'])->name('requisitions.create');
            Route::post('requisitions', [RequisitionController::class, 'store'])->name('requisitions.store');
            Route::get('requisitions/{requisition}/edit', [RequisitionController::class, 'edit'])->name('requisitions.edit');
            Route::put('requisitions/{requisition}', [RequisitionController::class, 'update'])->name('requisitions.update');
            Route::post('requisitions/{requisition}/submit', [RequisitionController::class, 'submit'])->name('requisitions.submit');
            Route::post('requisitions/{requisition}/amend', [RequisitionController::class, 'amend'])->name('requisitions.amend');
        });
        Route::post('requisitions/{requisition}/cancel', [RequisitionController::class, 'cancel'])->name('requisitions.cancel');
        Route::post('requisitions/{requisition}/decide', [RequisitionController::class, 'decide'])->middleware('can:procurement.requisitions.approve')->name('requisitions.decide');

        // Plans
        Route::middleware('can:procurement.view')->group(function () {
            Route::get('plans', [PlanController::class, 'index'])->name('plans.index');
            Route::get('plans/{plan}', [PlanController::class, 'show'])->name('plans.show');
        });
        Route::middleware('can:procurement.plans.manage')->group(function () {
            Route::post('plans', [PlanController::class, 'store'])->name('plans.store');
            Route::put('plans/{plan}', [PlanController::class, 'update'])->name('plans.update');
            Route::post('plans/{plan}/items/{item?}', [PlanController::class, 'storeItem'])->name('plans.items.save');
            Route::delete('plans/{plan}/items/{item}', [PlanController::class, 'destroyItem'])->name('plans.items.destroy');
            Route::post('plans/{plan}/submit', [PlanController::class, 'submit'])->name('plans.submit');
            Route::post('plans/{plan}/revise', [PlanController::class, 'revise'])->name('plans.revise');
            Route::post('plans/{plan}/close', [PlanController::class, 'close'])->name('plans.close');
        });
        Route::post('plans/{plan}/decide', [PlanController::class, 'decide'])->middleware('can:procurement.plans.approve')->name('plans.decide');

        // Suppliers
        Route::middleware('can:procurement.view')->group(function () {
            Route::get('suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
            Route::get('suppliers/{supplier}', [SupplierController::class, 'show'])->name('suppliers.show');
        });
        Route::middleware('can:procurement.suppliers.manage')->group(function () {
            Route::post('suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
            Route::put('suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');
            Route::post('suppliers/{supplier}/status', [SupplierController::class, 'status'])->name('suppliers.status');
        });

        // Sourcing, evaluation and awards
        Route::middleware('can:procurement.view')->group(function () {
            Route::get('sourcing', [SourcingController::class, 'index'])->name('sourcing.index');
            Route::get('sourcing/{event}', [SourcingController::class, 'show'])->name('sourcing.show');
            // Approval of a sourcing event: the rule's step decides who (App\Procurement\Approvals).
            Route::post('sourcing/{event}/decide', [SourcingController::class, 'decide'])->name('sourcing.decide');
        });
        Route::middleware('can:procurement.sourcing.manage')->group(function () {
            Route::post('sourcing', [SourcingController::class, 'store'])->name('sourcing.store');
            Route::put('sourcing/{event}', [SourcingController::class, 'update'])->name('sourcing.update');
            Route::post('sourcing/{event}/invitations', [SourcingController::class, 'invite'])->name('sourcing.invite');
            Route::delete('sourcing/{event}/invitations/{supplier}', [SourcingController::class, 'uninvite'])->name('sourcing.uninvite');
            Route::put('sourcing/{event}/criteria', [SourcingController::class, 'criteria'])->name('sourcing.criteria');
            Route::post('sourcing/{event}/issue', [SourcingController::class, 'issue'])->name('sourcing.issue');
            Route::post('sourcing/{event}/quotations', [SourcingController::class, 'storeQuotation'])->name('sourcing.quotations.store');
            Route::post('sourcing/{event}/quotations/{quotation}/withdraw', [SourcingController::class, 'withdrawQuotation'])->name('sourcing.quotations.withdraw');
            Route::post('sourcing/{event}/close', [SourcingController::class, 'close'])->name('sourcing.close');
            Route::post('sourcing/{event}/evaluation', [SourcingController::class, 'startEvaluation'])->name('sourcing.evaluation');
            Route::post('sourcing/{event}/award', [SourcingController::class, 'recommend'])->name('sourcing.award');
            Route::post('sourcing/{event}/awards/{award}/withdraw', [SourcingController::class, 'withdrawAward'])->name('sourcing.awards.withdraw');
            Route::post('sourcing/{event}/cancel', [SourcingController::class, 'cancel'])->name('sourcing.cancel');
        });
        Route::post('sourcing/{event}/quotations/{quotation}/evaluate', [SourcingController::class, 'evaluate'])->middleware('can:procurement.evaluate')->name('sourcing.quotations.evaluate');
        Route::post('sourcing/{event}/awards/{award}/decide', [SourcingController::class, 'decideAward'])->middleware('can:procurement.awards.approve')->name('sourcing.awards.decide');

        // Purchase orders, receiving and supplier invoices
        Route::middleware('can:procurement.view')->group(function () {
            Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
            Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
            Route::get('orders/{order}/print', [OrderController::class, 'print'])->name('orders.print');
            Route::get('receiving', [OrderController::class, 'receiving'])->name('receiving.index');
        });
        Route::middleware('can:procurement.orders.manage')->group(function () {
            Route::post('orders', [OrderController::class, 'store'])->name('orders.store');
            Route::post('awards/{award}/order', [OrderController::class, 'fromAward'])->name('orders.from-award');
            Route::put('orders/{order}', [OrderController::class, 'update'])->name('orders.update');
            Route::post('orders/{order}/submit', [OrderController::class, 'submit'])->name('orders.submit');
            Route::post('orders/{order}/issue', [OrderController::class, 'issue'])->name('orders.issue');
            Route::post('orders/{order}/amend', [OrderController::class, 'amend'])->name('orders.amend');
            Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');
            Route::post('orders/{order}/close', [OrderController::class, 'close'])->name('orders.close');
        });
        Route::post('orders/{order}/decide', [OrderController::class, 'decide'])->middleware('can:procurement.orders.approve')->name('orders.decide');
        Route::post('orders/{order}/receipts', [OrderController::class, 'receive'])->middleware('can:procurement.receive')->name('orders.receive');
        Route::middleware('can:procurement.invoices.manage')->group(function () {
            Route::post('orders/{order}/invoices', [OrderController::class, 'storeInvoice'])->name('orders.invoices.store');
            Route::post('invoices/{invoice}/void', [OrderController::class, 'voidInvoice'])->name('invoices.void');
            Route::post('invoices/{invoice}/paid', [OrderController::class, 'paidInvoice'])->name('invoices.paid');
        });

        // Documents on any record the member can open
        Route::post('documents/{type}/{id}', [DocumentController::class, 'store'])->whereUuid('id')->name('documents.store');
        Route::get('documents/{document}', [DocumentController::class, 'download'])->name('documents.download');

        // Reports, settings and audit
        Route::middleware('can:procurement.reports.view')->group(function () {
            Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
            Route::get('reports/orders.csv', [ReportController::class, 'export'])->name('reports.export');
        });
        Route::middleware('can:procurement.settings.manage')->prefix('settings')->name('settings.')->group(function () {
            Route::get('/', [SettingsController::class, 'show'])->name('show');
            Route::put('/', [SettingsController::class, 'update'])->name('update');
            Route::post('rules/{rule?}', [SettingsController::class, 'saveRule'])->name('rules.save');
            Route::post('currencies', [EntryCurrencyController::class, 'store'])->name('currencies.store');
            Route::delete('currencies/{code}', [EntryCurrencyController::class, 'destroy'])->where('code', '[A-Za-z]{3}')->name('currencies.destroy');
        });
        Route::get('audit', AuditController::class)->middleware('can:procurement.audit.view')->name('audit.index');
    });
