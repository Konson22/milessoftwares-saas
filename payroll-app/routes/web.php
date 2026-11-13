<?php

use App\Http\Controllers\AuditController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmployeePayController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\RunController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;
use Miles\PlatformClient\PlatformConfig;

/*
|--------------------------------------------------------------------------
| Payroll
|--------------------------------------------------------------------------
|
| Served at {workspace}/payroll: every route here (and every platform-client
| route) carries the path prefix. "platform" resolves the workspace from the
| host and requires a member whose platform access allows Payroll.
|
| Events from HR and products arrive at POST /payroll/events (routes/events.php).
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

        // People on the payroll (who they are; no pay).
        Route::get('employees', [EmployeeController::class, 'index'])->can('payroll.employees.view')->name('employees.index');
        Route::get('employees/{employee}', [EmployeeController::class, 'show'])->whereUuid('employee')->can('payroll.employees.view')->name('employees.show');
        Route::middleware('can:payroll.employees.manage')->group(function () {
            Route::get('employees/create', [EmployeeController::class, 'create'])->name('employees.create');
            Route::post('employees', [EmployeeController::class, 'store'])->name('employees.store');
            Route::put('employees/{employee}', [EmployeeController::class, 'update'])->whereUuid('employee')->name('employees.update');
            Route::post('employees/{employee}/leave', [EmployeeController::class, 'leave'])->whereUuid('employee')->name('employees.leave');
        });

        // Their pay: organization-wide only.
        Route::middleware('can:payroll.pay.manage')->group(function () {
            Route::post('employees/{employee}/pay', [EmployeePayController::class, 'storePay'])->whereUuid('employee')->name('employees.pay.store');
            Route::post('employees/{employee}/components', [EmployeePayController::class, 'storeComponent'])->whereUuid('employee')->name('employees.components.store');
            Route::put('employees/{employee}/components/{assigned}', [EmployeePayController::class, 'endComponent'])->whereUuid(['employee', 'assigned'])->name('employees.components.end');
            Route::put('employees/{employee}/payment-details', [EmployeePayController::class, 'updatePaymentDetails'])->whereUuid('employee')->name('employees.payment-details.update');
        });

        // Payroll runs and payslips.
        Route::middleware('can:payroll.pay.view')->group(function () {
            Route::get('runs', [RunController::class, 'index'])->name('runs.index');
            Route::get('runs/{run}', [RunController::class, 'show'])->whereUuid('run')->name('runs.show');
            Route::get('runs/{run}/payslips/{payslip}', [RunController::class, 'payslip'])->whereUuid(['run', 'payslip'])->name('runs.payslip');
            Route::get('runs/{run}/payslips/{payslip}/pdf', [RunController::class, 'payslipPdf'])->whereUuid(['run', 'payslip'])->name('runs.payslip.pdf');
            Route::get('runs/{run}/payslips.pdf', [RunController::class, 'payslipsPdf'])->whereUuid('run')->name('runs.payslips.pdf');
            Route::middleware('can:payroll.runs.prepare')->group(function () {
                Route::post('runs', [RunController::class, 'store'])->name('runs.store');
                Route::post('runs/{run}/recalculate', [RunController::class, 'recalculate'])->whereUuid('run')->name('runs.recalculate');
                Route::delete('runs/{run}', [RunController::class, 'destroy'])->whereUuid('run')->name('runs.destroy');
            });
            Route::post('runs/{run}/review', [RunController::class, 'review'])->whereUuid('run')->can('payroll.runs.review')->name('runs.review');
            Route::post('runs/{run}/approve', [RunController::class, 'approve'])->whereUuid('run')->can('payroll.runs.approve')->name('runs.approve');
            Route::post('runs/{run}/reverse', [RunController::class, 'reverse'])->whereUuid('run')->can('payroll.runs.approve')->name('runs.reverse');
            Route::post('runs/{run}/pay', [RunController::class, 'pay'])->whereUuid('run')->can('payroll.runs.pay')->name('runs.pay');
        });

        // Who did what: runs, pay, payment details and rules.
        Route::get('audit', AuditController::class)->can('payroll.audit.view')->name('audit');

        // Allowances, deductions, income tax and social security.
        Route::middleware('can:payroll.settings')->group(function () {
            Route::get('settings', [SettingsController::class, 'index'])->name('settings');
            Route::post('settings/components', [SettingsController::class, 'storeComponent'])->name('settings.components.store');
            Route::put('settings/components/{component}', [SettingsController::class, 'updateComponent'])->whereUuid('component')->name('settings.components.update');
            Route::post('settings/rules', [SettingsController::class, 'storeRule'])->name('settings.rules.store');
        });
    });
