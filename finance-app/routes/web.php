<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\BankingController;
use App\Http\Controllers\BillController;
use App\Http\Controllers\CustomerPaymentController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\JournalController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\SupplierPaymentController;
use Illuminate\Support\Facades\Route;
use Miles\PlatformClient\PlatformConfig;

/*
|--------------------------------------------------------------------------
| Finance
|--------------------------------------------------------------------------
|
| Served at {workspace}/finance: every route here (and every platform-client
| route) carries the path prefix. "platform" resolves the workspace from the
| host and requires a member whose platform access allows Finance. The books
| are the organization's: permissions apply organization-wide.
|
| Products' business events arrive at POST /finance/events (routes/events.php).
|
*/

// Not connected yet (fresh checkout, no .env): boot without product routes.
if (! app(PlatformConfig::class)->configured()) {
    return;
}

Route::prefix(app(PlatformConfig::class)->path())
    ->middleware(['web', 'platform'])
    ->group(function () {
        Route::get('/', HomeController::class)->name('home');

        // Chart of accounts and each account's ledger.
        Route::middleware('can:finance.accounts.view')->group(function () {
            Route::get('accounts', [AccountController::class, 'index'])->name('accounts.index');
            Route::get('accounts/{account}', [AccountController::class, 'show'])->whereUuid('account')->name('accounts.show');
        });
        Route::middleware('can:finance.accounts.manage')->group(function () {
            Route::post('accounts', [AccountController::class, 'store'])->name('accounts.store');
            Route::put('accounts/{account}', [AccountController::class, 'update'])->whereUuid('account')->name('accounts.update');
        });

        // Journals.
        Route::middleware('can:finance.journals.view')->group(function () {
            Route::get('journals', [JournalController::class, 'index'])->name('journals.index');
            Route::get('journals/{journal}', [JournalController::class, 'show'])->whereUuid('journal')->name('journals.show');
        });
        Route::middleware('can:finance.journals.post')->group(function () {
            Route::get('journals/create', [JournalController::class, 'create'])->name('journals.create');
            Route::post('journals', [JournalController::class, 'store'])->name('journals.store');
            Route::post('journals/{journal}/reverse', [JournalController::class, 'reverse'])->whereUuid('journal')->name('journals.reverse');
        });

        // Payables.
        Route::middleware('can:finance.payables.view')->group(function () {
            Route::get('suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
            Route::get('suppliers/{supplier}', [SupplierController::class, 'show'])->whereUuid('supplier')->name('suppliers.show');
            Route::get('bills', [BillController::class, 'index'])->name('bills.index');
            Route::get('bills/{bill}', [BillController::class, 'show'])->whereUuid('bill')->name('bills.show');
            Route::get('supplier-payments', [SupplierPaymentController::class, 'index'])->name('supplier-payments.index');
        });
        Route::middleware('can:finance.payables.manage')->group(function () {
            Route::post('suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
            Route::put('suppliers/{supplier}', [SupplierController::class, 'update'])->whereUuid('supplier')->name('suppliers.update');
            Route::get('bills/create', [BillController::class, 'create'])->name('bills.create');
            Route::post('bills', [BillController::class, 'store'])->name('bills.store');
            Route::get('supplier-payments/create', [SupplierPaymentController::class, 'create'])->name('supplier-payments.create');
            Route::post('supplier-payments', [SupplierPaymentController::class, 'store'])->name('supplier-payments.store');
        });

        // Receivables.
        Route::middleware('can:finance.receivables.view')->group(function () {
            Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
            Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->whereUuid('invoice')->name('invoices.show');
            Route::get('customer-payments', [CustomerPaymentController::class, 'index'])->name('customer-payments.index');
        });
        Route::middleware('can:finance.receivables.manage')->group(function () {
            Route::get('invoices/create', [InvoiceController::class, 'create'])->name('invoices.create');
            Route::post('invoices', [InvoiceController::class, 'store'])->name('invoices.store');
            Route::post('invoices/{invoice}/void', [InvoiceController::class, 'void'])->whereUuid('invoice')->name('invoices.void');
            Route::post('invoices/{invoice}/payments', [CustomerPaymentController::class, 'store'])->whereUuid('invoice')->name('customer-payments.store');
        });

        // Banks and cash.
        Route::middleware('can:finance.banking.view')->group(function () {
            Route::get('banking', [BankingController::class, 'index'])->name('banking.index');
        });
        Route::middleware('can:finance.banking.manage')->group(function () {
            Route::post('banking/money-out', [BankingController::class, 'moneyOut'])->name('banking.money-out');
            Route::post('banking/money-in', [BankingController::class, 'moneyIn'])->name('banking.money-in');
            Route::post('banking/transfers', [BankingController::class, 'transfer'])->name('banking.transfer');
        });

        // Statements.
        Route::middleware('can:finance.reports.view')->group(function () {
            Route::get('reports/trial-balance', [ReportController::class, 'trialBalance'])->name('reports.trial-balance');
            Route::get('reports/profit-and-loss', [ReportController::class, 'profitAndLoss'])->name('reports.profit-and-loss');
            Route::get('reports/balance-sheet', [ReportController::class, 'balanceSheet'])->name('reports.balance-sheet');
        });
    });
