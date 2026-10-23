<?php

use App\Http\Controllers\HomeController;
use App\Modules\Documents\Http\Controllers\DocumentController;
use App\Modules\Employees\Http\Controllers\EmployeeController;
use App\Modules\Employees\Http\Controllers\EmploymentController;
use App\Modules\Employees\Http\Controllers\PersonalDetailsController;
use App\Modules\Leave\Http\Controllers\EmployeeLeaveController;
use App\Modules\Leave\Http\Controllers\LeaveController;
use App\Modules\Leave\Http\Controllers\LeaveSettingsController;
use Illuminate\Support\Facades\Route;
use Miles\PlatformClient\PlatformConfig;

/*
|--------------------------------------------------------------------------
| HR & Payroll
|--------------------------------------------------------------------------
|
| Served at {workspace}/hr: every route here (and every platform-client
| route) carries the path prefix. "platform" resolves the workspace from the
| host and requires a member whose platform access allows HR.
|
| The route's permission lets a member in; which staff they reach (their
| branch or department, or everyone) is checked per employee (StaffScope).
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

        // Staff profiles and work details.
        Route::get('employees', [EmployeeController::class, 'index'])->can('hr.employees.view')->name('employees.index');
        Route::get('employees/{employee}', [EmployeeController::class, 'show'])->whereUuid('employee')->can('hr.employees.view')->name('employees.show');
        Route::middleware('can:hr.employees.manage')->group(function () {
            Route::get('employees/create', [EmployeeController::class, 'create'])->name('employees.create');
            Route::post('employees', [EmployeeController::class, 'store'])->name('employees.store');
            Route::put('employees/{employee}', [EmployeeController::class, 'update'])->whereUuid('employee')->name('employees.update');
        });

        // Moves, contracts and leaving.
        Route::middleware('can:hr.employment.manage')->group(function () {
            Route::post('employees/{employee}/moves', [EmploymentController::class, 'move'])->whereUuid('employee')->name('employees.moves.store');
            Route::post('employees/{employee}/contracts', [EmploymentController::class, 'contract'])->whereUuid('employee')->name('employees.contracts.store');
            Route::post('employees/{employee}/leave', [EmploymentController::class, 'leave'])->whereUuid('employee')->name('employees.leave');
        });

        // Personal details: their own page, organization-wide permissions only.
        Route::get('employees/{employee}/personal', [PersonalDetailsController::class, 'show'])->whereUuid('employee')->can('hr.personal.view')->name('employees.personal.show');
        Route::put('employees/{employee}/personal', [PersonalDetailsController::class, 'update'])->whereUuid('employee')->can('hr.personal.manage')->name('employees.personal.update');

        // Documents: listed and opened through HR, downloaded only by a
        // short-lived signed link (relative: the host is the workspace's).
        Route::get('employees/{employee}/documents', [DocumentController::class, 'index'])->whereUuid('employee')->can('hr.documents.view')->name('employees.documents.index');
        Route::post('employees/{employee}/documents', [DocumentController::class, 'store'])->whereUuid('employee')->can('hr.documents.manage')->name('employees.documents.store');
        Route::get('documents/{document}/open', [DocumentController::class, 'open'])->whereUuid('document')->can('hr.documents.view')->name('documents.open');
        Route::get('documents/{document}/download', [DocumentController::class, 'download'])->whereUuid('document')->middleware('signed:relative')->can('hr.documents.view')->name('documents.download');
        Route::delete('documents/{document}', [DocumentController::class, 'destroy'])->whereUuid('document')->can('hr.documents.manage')->name('documents.destroy');

        // Leave: requests and decisions, each person's balances, and the
        // organization's kinds of leave and public holidays.
        Route::get('leave', [LeaveController::class, 'index'])->can('hr.leave.view')->name('leave.index');
        Route::post('leave', [LeaveController::class, 'store'])->can('hr.leave.manage')->name('leave.store');
        Route::post('leave/{leaveRequest}/approve', [LeaveController::class, 'approve'])->whereUuid('leaveRequest')->can('hr.leave.approve')->name('leave.approve');
        Route::post('leave/{leaveRequest}/reject', [LeaveController::class, 'reject'])->whereUuid('leaveRequest')->can('hr.leave.approve')->name('leave.reject');
        // Whoever recorded a request may withdraw it; the controller checks.
        Route::post('leave/{leaveRequest}/cancel', [LeaveController::class, 'cancel'])->whereUuid('leaveRequest')->can('hr.leave.view')->name('leave.cancel');
        Route::get('employees/{employee}/leave-balances', [EmployeeLeaveController::class, 'show'])->whereUuid('employee')->can('hr.leave.view')->name('employees.balances.show');
        Route::put('employees/{employee}/leave-balances/{leaveType}', [EmployeeLeaveController::class, 'update'])->whereUuid(['employee', 'leaveType'])->can('hr.leave.manage')->name('employees.balances.update');
        Route::middleware('can:hr.leave.settings')->group(function () {
            Route::get('leave/settings', [LeaveSettingsController::class, 'index'])->name('leave.settings');
            Route::post('leave/types', [LeaveSettingsController::class, 'storeType'])->name('leave.types.store');
            Route::put('leave/types/{leaveType}', [LeaveSettingsController::class, 'updateType'])->whereUuid('leaveType')->name('leave.types.update');
            Route::post('leave/holidays', [LeaveSettingsController::class, 'storeHoliday'])->name('leave.holidays.store');
            Route::delete('leave/holidays/{publicHoliday}', [LeaveSettingsController::class, 'destroyHoliday'])->whereUuid('publicHoliday')->name('leave.holidays.destroy');
        });
    });
