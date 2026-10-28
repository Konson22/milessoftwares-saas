<?php

use App\Finance\Currencies;
use App\Finance\FixedAssets;
use App\Finance\JournalApprovals;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\AssetCategoryController;
use App\Http\Controllers\BankingController;
use App\Http\Controllers\BankRuleController;
use App\Http\Controllers\BankStatementController;
use App\Http\Controllers\BillController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\CurrencyController;
use App\Http\Controllers\CustomerPaymentController;
use App\Http\Controllers\DepreciationController;
use App\Http\Controllers\DimensionController;
use App\Http\Controllers\FixedAssetController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\JournalApprovalController;
use App\Http\Controllers\JournalController;
use App\Http\Controllers\OpeningBalanceController;
use App\Http\Controllers\PeriodController;
use App\Http\Controllers\RecurringJournalController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\SupplierPaymentController;
use App\Http\Controllers\TaxCodeController;
use App\Http\Controllers\TaxReturnController;
use App\Http\Middleware\RequireFeature;
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

        // Cost centres and projects.
        Route::middleware('can:finance.accounts.view')->group(function () {
            Route::get('dimensions', [DimensionController::class, 'index'])->name('dimensions.index');
        });
        Route::middleware('can:finance.accounts.manage')->group(function () {
            Route::post('dimensions', [DimensionController::class, 'store'])->name('dimensions.store');
            Route::put('dimensions/{value}', [DimensionController::class, 'update'])->whereUuid('value')->name('dimensions.update');
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

        // Journal approvals (maker-checker): drafts wait for a second person.
        Route::middleware('can:finance.journals.view')->group(function () {
            Route::get('journal-approvals', [JournalApprovalController::class, 'index'])->name('journal-approvals.index');
        });
        Route::middleware('can:finance.journals.post')->group(function () {
            Route::post('journal-approvals/{draft}/withdraw', [JournalApprovalController::class, 'withdraw'])->whereUuid('draft')->name('journal-approvals.withdraw');
        });
        Route::middleware('can:'.JournalApprovals::PERMISSION)->group(function () {
            Route::post('journal-approvals/{draft}/approve', [JournalApprovalController::class, 'approve'])->whereUuid('draft')->name('journal-approvals.approve');
            Route::post('journal-approvals/{draft}/reject', [JournalApprovalController::class, 'reject'])->whereUuid('draft')->name('journal-approvals.reject');
            Route::put('journal-approvals/settings', [JournalApprovalController::class, 'settings'])->name('journal-approvals.settings');
            Route::post('recurring-journals/{recurringJournal}/approve', [RecurringJournalController::class, 'approve'])->whereUuid('recurringJournal')->name('recurring-journals.approve');
        });

        // Recurring journals: posted on a schedule (finance:recurring), or now.
        Route::middleware('can:finance.journals.view')->group(function () {
            Route::get('recurring-journals', [RecurringJournalController::class, 'index'])->name('recurring-journals.index');
        });
        Route::middleware('can:finance.journals.post')->group(function () {
            Route::get('recurring-journals/create', [RecurringJournalController::class, 'create'])->name('recurring-journals.create');
            Route::post('recurring-journals', [RecurringJournalController::class, 'store'])->name('recurring-journals.store');
            Route::get('recurring-journals/{recurringJournal}/edit', [RecurringJournalController::class, 'edit'])->whereUuid('recurringJournal')->name('recurring-journals.edit');
            Route::put('recurring-journals/{recurringJournal}', [RecurringJournalController::class, 'update'])->whereUuid('recurringJournal')->name('recurring-journals.update');
            Route::post('recurring-journals/{recurringJournal}/toggle', [RecurringJournalController::class, 'toggle'])->whereUuid('recurringJournal')->name('recurring-journals.toggle');
            Route::post('recurring-journals/{recurringJournal}/run', [RecurringJournalController::class, 'run'])->whereUuid('recurringJournal')->name('recurring-journals.run');
            Route::delete('recurring-journals/{recurringJournal}', [RecurringJournalController::class, 'destroy'])->whereUuid('recurringJournal')->name('recurring-journals.destroy');
        });

        // Financial years, month locks, year-end close and opening balances.
        Route::middleware('can:finance.journals.view')->group(function () {
            Route::get('periods', [PeriodController::class, 'index'])->name('periods.index');
        });
        Route::middleware('can:finance.periods.manage')->group(function () {
            Route::post('periods/years', [PeriodController::class, 'storeYear'])->name('periods.years.store');
            Route::post('periods/{period}/lock', [PeriodController::class, 'lock'])->whereUuid('period')->name('periods.lock');
            Route::post('periods/{period}/unlock', [PeriodController::class, 'unlock'])->whereUuid('period')->name('periods.unlock');
            Route::post('periods/years/{year}/close', [PeriodController::class, 'close'])->whereUuid('year')->name('periods.years.close');
            Route::post('periods/years/{year}/reopen', [PeriodController::class, 'reopen'])->whereUuid('year')->name('periods.years.reopen');
            Route::get('opening-balances', [OpeningBalanceController::class, 'edit'])->name('opening-balances.edit');
            Route::put('opening-balances', [OpeningBalanceController::class, 'update'])->name('opening-balances.update');
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

        // Bank statements and reconciliation.
        Route::middleware('can:finance.banking.view')->group(function () {
            Route::get('reconciliation', [BankStatementController::class, 'index'])->name('reconciliation.index');
            Route::get('reconciliation/{statement}', [BankStatementController::class, 'show'])->whereUuid('statement')->name('reconciliation.show');
            Route::get('bank-rules', [BankRuleController::class, 'index'])->name('bank-rules.index');
        });
        Route::middleware('can:finance.banking.reconcile')->group(function () {
            Route::post('reconciliation', [BankStatementController::class, 'store'])->name('reconciliation.store');
            Route::delete('reconciliation/{statement}', [BankStatementController::class, 'destroy'])->whereUuid('statement')->name('reconciliation.destroy');
            Route::post('reconciliation/{statement}/auto', [BankStatementController::class, 'auto'])->whereUuid('statement')->name('reconciliation.auto');
            Route::post('reconciliation/{statement}/reconcile', [BankStatementController::class, 'reconcile'])->whereUuid('statement')->name('reconciliation.reconcile');
            Route::post('reconciliation/{statement}/undo', [BankStatementController::class, 'undo'])->whereUuid('statement')->name('reconciliation.undo');
            Route::post('reconciliation/lines/{line}/match', [BankStatementController::class, 'match'])->whereUuid('line')->name('reconciliation.lines.match');
            Route::post('reconciliation/lines/{line}/unmatch', [BankStatementController::class, 'unmatch'])->whereUuid('line')->name('reconciliation.lines.unmatch');
            Route::post('reconciliation/lines/{line}/enter', [BankStatementController::class, 'enter'])->whereUuid('line')->name('reconciliation.lines.enter');
            Route::post('bank-rules', [BankRuleController::class, 'store'])->name('bank-rules.store');
            Route::delete('bank-rules/{rule}', [BankRuleController::class, 'destroy'])->whereUuid('rule')->name('bank-rules.destroy');
        });

        // Tax codes and returns.
        Route::middleware('can:finance.reports.view')->group(function () {
            Route::get('tax-codes', [TaxCodeController::class, 'index'])->name('tax-codes.index');
            Route::get('tax-returns', [TaxReturnController::class, 'index'])->name('tax-returns.index');
            Route::get('tax-returns/{return}', [TaxReturnController::class, 'show'])->whereUuid('return')->name('tax-returns.show');
        });
        Route::middleware('can:finance.tax.manage')->group(function () {
            Route::post('tax-codes', [TaxCodeController::class, 'store'])->name('tax-codes.store');
            Route::put('tax-codes/{code}', [TaxCodeController::class, 'update'])->whereUuid('code')->name('tax-codes.update');
            Route::post('tax-returns', [TaxReturnController::class, 'store'])->name('tax-returns.store');
            Route::post('tax-returns/{return}/settle', [TaxReturnController::class, 'settle'])->whereUuid('return')->name('tax-returns.settle');
            Route::delete('tax-returns/{return}', [TaxReturnController::class, 'destroy'])->whereUuid('return')->name('tax-returns.destroy');
        });

        // Budgets.
        Route::middleware('can:finance.reports.view')->group(function () {
            Route::get('budgets', [BudgetController::class, 'index'])->name('budgets.index');
            Route::get('budgets/{budget}', [BudgetController::class, 'show'])->whereUuid('budget')->name('budgets.show');
            Route::get('reports/budget-vs-actual', [BudgetController::class, 'report'])->name('reports.budget-vs-actual');
        });
        Route::middleware('can:finance.budgets.manage')->group(function () {
            Route::post('budgets', [BudgetController::class, 'store'])->name('budgets.store');
            Route::put('budgets/{budget}', [BudgetController::class, 'update'])->whereUuid('budget')->name('budgets.update');
            Route::delete('budgets/{budget}', [BudgetController::class, 'destroy'])->whereUuid('budget')->name('budgets.destroy');
        });

        // Fixed assets: categories, the register and depreciation (plan feature).
        Route::middleware([RequireFeature::class.':'.FixedAssets::FEATURE, 'can:finance.accounts.view'])->group(function () {
            Route::get('fixed-assets', [FixedAssetController::class, 'index'])->name('fixed-assets.index');
            Route::get('fixed-assets/{asset}', [FixedAssetController::class, 'show'])->whereUuid('asset')->name('fixed-assets.show');
            Route::get('asset-categories', [AssetCategoryController::class, 'index'])->name('asset-categories.index');
            Route::get('depreciation', [DepreciationController::class, 'index'])->name('depreciation.index');
        });
        Route::middleware([RequireFeature::class.':'.FixedAssets::FEATURE, 'can:finance.assets.manage'])->group(function () {
            Route::post('fixed-assets', [FixedAssetController::class, 'store'])->name('fixed-assets.store');
            Route::post('fixed-assets/{asset}/dispose', [FixedAssetController::class, 'dispose'])->whereUuid('asset')->name('fixed-assets.dispose');
            Route::post('asset-categories', [AssetCategoryController::class, 'store'])->name('asset-categories.store');
            Route::put('asset-categories/{category}', [AssetCategoryController::class, 'update'])->whereUuid('category')->name('asset-categories.update');
            Route::post('depreciation', [DepreciationController::class, 'store'])->name('depreciation.store');
            Route::delete('depreciation/{run}', [DepreciationController::class, 'destroy'])->whereUuid('run')->name('depreciation.destroy');
        });

        // Other currencies: exchange rates and revaluation (plan feature).
        Route::middleware([RequireFeature::class.':'.Currencies::FEATURE, 'can:finance.reports.view'])->group(function () {
            Route::get('currencies', [CurrencyController::class, 'index'])->name('currencies.index');
        });
        Route::middleware([RequireFeature::class.':'.Currencies::FEATURE, 'can:finance.currencies.manage'])->group(function () {
            Route::post('currencies/rates', [CurrencyController::class, 'storeRate'])->name('currencies.rates.store');
            Route::delete('currencies/rates/{rate}', [CurrencyController::class, 'destroyRate'])->whereUuid('rate')->name('currencies.rates.destroy');
            Route::post('currencies/revaluations', [CurrencyController::class, 'revalue'])->name('currencies.revalue');
        });

        // Statements.
        Route::middleware('can:finance.reports.view')->group(function () {
            Route::get('reports/trial-balance', [ReportController::class, 'trialBalance'])->name('reports.trial-balance');
            Route::get('reports/profit-and-loss', [ReportController::class, 'profitAndLoss'])->name('reports.profit-and-loss');
            Route::get('reports/profit-and-loss-by-dimension', [ReportController::class, 'profitAndLossByDimension'])->name('reports.profit-and-loss-by-dimension');
            Route::get('reports/balance-sheet', [ReportController::class, 'balanceSheet'])->name('reports.balance-sheet');
            Route::get('reports/cash-flow', [ReportController::class, 'cashFlow'])->name('reports.cash-flow');
            Route::get('reports/ratios', [ReportController::class, 'ratios'])->name('reports.ratios');
            Route::get('reports/general-ledger', [ReportController::class, 'generalLedger'])->name('reports.general-ledger');
            Route::get('reports/aged-receivables', [ReportController::class, 'agedReceivables'])->name('reports.aged-receivables');
            Route::get('reports/aged-payables', [ReportController::class, 'agedPayables'])->name('reports.aged-payables');
        });
    });
