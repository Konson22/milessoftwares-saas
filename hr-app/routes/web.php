<?php

use App\Http\Controllers\HomeController;
use App\Modules\Employees\Http\Controllers\EmployeeController;
use App\Modules\Employees\Http\Controllers\EmploymentController;
use App\Modules\Employees\Http\Controllers\PersonalDetailsController;
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
    });
