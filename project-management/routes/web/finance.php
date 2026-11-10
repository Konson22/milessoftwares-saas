<?php

use App\Http\Controllers\Finance\BudgetController;
use App\Http\Controllers\Finance\ExpenseController;
use Illuminate\Support\Facades\Route;

/*
| Finance: a project's budget versions and its spending, from request to
| payment. Inside {slug}.domain/projects/{project}
| (routes/web/projects.php). Who may do what to one expense (cancel,
| attach) is decided in ExpenseController; the rules in App\Actions.
*/

Route::middleware('can:projects.finance.view')->group(function () {
    Route::get('budget', [BudgetController::class, 'show'])->name('budget');
    Route::get('budget/export', [BudgetController::class, 'export'])->name('budget.export');
    Route::get('expenses/export', [ExpenseController::class, 'export'])->name('expenses.export');
});

Route::middleware(['can:projects.finance.view', 'can:projects.manage'])->group(function () {
    Route::post('budget/lines', [BudgetController::class, 'addLine'])->name('budget.lines.store');
    Route::delete('budget/lines/{budgetLine}', [BudgetController::class, 'removeLine'])->whereUuid('budgetLine')->name('budget.lines.destroy');
    Route::post('budget/versions', [BudgetController::class, 'revise'])->name('budget.versions.store');
    Route::post('budget/versions/{budgetVersion}/submit', [BudgetController::class, 'submit'])->whereUuid('budgetVersion')->name('budget.versions.submit');
    Route::delete('budget/versions/{budgetVersion}', [BudgetController::class, 'discard'])->whereUuid('budgetVersion')->name('budget.versions.destroy');
    // Spending already paid, in one step (marked direct).
    Route::post('expenses', [ExpenseController::class, 'record'])->name('expenses.store');
});

Route::middleware(['can:projects.finance.view', 'can:finance.budgets.approve'])->group(function () {
    Route::post('budget/versions/{budgetVersion}/approve', [BudgetController::class, 'approve'])->whereUuid('budgetVersion')->name('budget.versions.approve');
    Route::post('budget/versions/{budgetVersion}/return', [BudgetController::class, 'returnToDraft'])->whereUuid('budgetVersion')->name('budget.versions.return');
});

Route::get('expenses', [ExpenseController::class, 'index'])->can('projects.view')->name('expenses.index');
Route::post('expenses/requests', [ExpenseController::class, 'request'])->can('projects.expenses.request')->name('expenses.request');

Route::prefix('expenses/{expense}')->whereUuid('expense')->middleware('can:projects.view')->name('expenses.')->group(function () {
    Route::post('approve', [ExpenseController::class, 'approve'])->can('finance.expenses.approve')->name('approve');
    Route::post('reject', [ExpenseController::class, 'reject'])->can('finance.expenses.approve')->name('reject');
    Route::post('pay', [ExpenseController::class, 'pay'])->can('finance.payments.record')->name('pay');
    Route::post('cancel', [ExpenseController::class, 'cancel'])->name('cancel');
    Route::post('documents', [ExpenseController::class, 'attach'])->name('documents.store');
    Route::get('documents/{document}', [ExpenseController::class, 'document'])->whereUuid('document')->name('documents.show');
    Route::delete('documents/{document}', [ExpenseController::class, 'detach'])->whereUuid('document')->name('documents.destroy');
});
