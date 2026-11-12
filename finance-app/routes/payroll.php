<?php

use App\Http\Controllers\Payroll\EmployeeController;
use App\Http\Controllers\Payroll\EmployeePayController;
use App\Http\Controllers\Payroll\HomeController;
use App\Http\Controllers\Payroll\RunController;
use App\Http\Controllers\Payroll\SettingsController;
use App\Http\Middleware\RequireAnyPermission;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Payroll, part of Finance (ADR-0009)
|--------------------------------------------------------------------------
|
| {workspace}/finance/payroll/..., inside routes/web.php's group. People
| (branch-scoped), their pay, runs and payslips, and the statutory rules.
| Approved and paid runs post to the books directly (App\Payroll\Runs).
|
*/

Route::prefix('payroll')->name('payroll.')->group(function () {
    Route::get('/', HomeController::class)
        ->middleware(RequireAnyPermission::class.':finance.payroll.employees.view,finance.payroll.pay.view,finance.payroll.settings')
        ->name('home');

    // People on the payroll (who they are; no pay).
    Route::get('employees', [EmployeeController::class, 'index'])->can('finance.payroll.employees.view')->name('employees.index');
    Route::get('employees/{employee}', [EmployeeController::class, 'show'])->whereUuid('employee')->can('finance.payroll.employees.view')->name('employees.show');
    Route::middleware('can:finance.payroll.employees.manage')->group(function () {
        Route::get('employees/create', [EmployeeController::class, 'create'])->name('employees.create');
        Route::post('employees', [EmployeeController::class, 'store'])->name('employees.store');
        Route::put('employees/{employee}', [EmployeeController::class, 'update'])->whereUuid('employee')->name('employees.update');
        Route::post('employees/{employee}/leave', [EmployeeController::class, 'leave'])->whereUuid('employee')->name('employees.leave');
    });

    // Their pay: organization-wide only.
    Route::middleware('can:finance.payroll.pay.manage')->group(function () {
        Route::post('employees/{employee}/pay', [EmployeePayController::class, 'storePay'])->whereUuid('employee')->name('employees.pay.store');
        Route::post('employees/{employee}/components', [EmployeePayController::class, 'storeComponent'])->whereUuid('employee')->name('employees.components.store');
        Route::put('employees/{employee}/components/{assigned}', [EmployeePayController::class, 'endComponent'])->whereUuid(['employee', 'assigned'])->name('employees.components.end');
        Route::put('employees/{employee}/payment-details', [EmployeePayController::class, 'updatePaymentDetails'])->whereUuid('employee')->name('employees.payment-details.update');
    });

    // Payroll runs and payslips.
    Route::middleware('can:finance.payroll.pay.view')->group(function () {
        Route::get('runs', [RunController::class, 'index'])->name('runs.index');
        Route::get('runs/{run}', [RunController::class, 'show'])->whereUuid('run')->name('runs.show');
        Route::get('runs/{run}/payslips/{payslip}', [RunController::class, 'payslip'])->whereUuid(['run', 'payslip'])->name('runs.payslip');
        Route::get('runs/{run}/payslips/{payslip}/pdf', [RunController::class, 'payslipPdf'])->whereUuid(['run', 'payslip'])->name('runs.payslip.pdf');
        Route::get('runs/{run}/payslips.pdf', [RunController::class, 'payslipsPdf'])->whereUuid('run')->name('runs.payslips.pdf');
        Route::middleware('can:finance.payroll.runs.prepare')->group(function () {
            Route::post('runs', [RunController::class, 'store'])->name('runs.store');
            Route::post('runs/{run}/recalculate', [RunController::class, 'recalculate'])->whereUuid('run')->name('runs.recalculate');
            Route::delete('runs/{run}', [RunController::class, 'destroy'])->whereUuid('run')->name('runs.destroy');
        });
        Route::post('runs/{run}/approve', [RunController::class, 'approve'])->whereUuid('run')->can('finance.payroll.runs.approve')->name('runs.approve');
        Route::post('runs/{run}/pay', [RunController::class, 'pay'])->whereUuid('run')->can('finance.payroll.runs.pay')->name('runs.pay');
    });

    // Allowances, deductions, income tax and social security.
    Route::middleware('can:finance.payroll.settings')->group(function () {
        Route::get('settings', [SettingsController::class, 'index'])->name('settings');
        Route::post('settings/components', [SettingsController::class, 'storeComponent'])->name('settings.components.store');
        Route::put('settings/components/{component}', [SettingsController::class, 'updateComponent'])->whereUuid('component')->name('settings.components.update');
        Route::post('settings/rules', [SettingsController::class, 'storeRule'])->name('settings.rules.store');
    });
});
