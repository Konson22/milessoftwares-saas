<?php

use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\ClassController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\FeeController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\MarkController;
use App\Http\Controllers\ProgrammeController;
use App\Http\Controllers\ResultController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\SubjectController;
use Illuminate\Support\Facades\Route;
use Miles\PlatformClient\PlatformConfig;

/*
|--------------------------------------------------------------------------
| Product pages
|--------------------------------------------------------------------------
|
| Served at {workspace}/<path>: every route here (and every platform-client
| route) carries the path prefix. The proxy must not strip it. The
| "platform" middleware resolves the workspace from the host and requires a
| signed-in member whose platform access allows this product.
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

        // Once: what kind of school this is, and its first academic year.
        Route::middleware('can:school.settings.manage')->group(function () {
            Route::get('setup', [SetupController::class, 'create'])->name('setup.create');
            Route::post('setup', [SetupController::class, 'store'])->name('setup.store');
        });

        // Everything else needs the school set up.
        Route::middleware('school.setup')->group(function () {
            // The calendar and assessments.
            Route::middleware('can:school.settings.manage')->group(function () {
                Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
                Route::put('settings/category', [SettingsController::class, 'updateCategory'])->name('settings.category.update');
                Route::post('settings/years', [SettingsController::class, 'storeYear'])->name('settings.years.store');
                Route::put('settings/terms/{term}', [SettingsController::class, 'updateTerm'])->whereUuid('term')->name('settings.terms.update');
                Route::post('settings/terms/{term}/current', [SettingsController::class, 'makeCurrent'])->whereUuid('term')->name('settings.terms.current');
                Route::post('settings/terms/{term}/assessments', [SettingsController::class, 'storeAssessment'])->whereUuid('term')->name('settings.assessments.store');
                Route::delete('settings/assessments/{assessment}', [SettingsController::class, 'destroyAssessment'])->whereUuid('assessment')->name('settings.assessments.destroy');
            });

            // How the school is organized: departments, programmes, classes,
            // subjects and staff.
            Route::middleware('can:school.academics.manage')->group(function () {
                Route::post('departments/faculties', [DepartmentController::class, 'storeFaculty'])->name('faculties.store');
                Route::delete('departments/faculties/{faculty}', [DepartmentController::class, 'destroyFaculty'])->whereUuid('faculty')->name('faculties.destroy');
                Route::post('departments', [DepartmentController::class, 'storeDepartment'])->name('departments.store');
                Route::delete('departments/{department}', [DepartmentController::class, 'destroyDepartment'])->whereUuid('department')->name('departments.destroy');

                Route::get('programmes/create', [ProgrammeController::class, 'create'])->name('programmes.create');
                Route::post('programmes', [ProgrammeController::class, 'store'])->name('programmes.store');
                Route::get('programmes/{programme}/edit', [ProgrammeController::class, 'edit'])->whereUuid('programme')->name('programmes.edit');
                Route::put('programmes/{programme}', [ProgrammeController::class, 'update'])->whereUuid('programme')->name('programmes.update');

                Route::get('classes/create', [ClassController::class, 'create'])->name('classes.create');
                Route::post('classes', [ClassController::class, 'store'])->name('classes.store');
                Route::get('classes/{class}/edit', [ClassController::class, 'edit'])->whereUuid('class')->name('classes.edit');
                Route::put('classes/{class}', [ClassController::class, 'update'])->whereUuid('class')->name('classes.update');
                Route::post('classes/{class}/subjects', [ClassController::class, 'addSubject'])->whereUuid('class')->name('classes.subjects.store');
                Route::put('classes/{class}/subjects/{classSubject}', [ClassController::class, 'updateSubject'])->whereUuid(['class', 'classSubject'])->name('classes.subjects.update');
                Route::delete('classes/{class}/subjects/{classSubject}', [ClassController::class, 'removeSubject'])->whereUuid(['class', 'classSubject'])->name('classes.subjects.destroy');

                Route::get('subjects/create', [SubjectController::class, 'create'])->name('subjects.create');
                Route::post('subjects', [SubjectController::class, 'store'])->name('subjects.store');
                Route::get('subjects/{subject}/edit', [SubjectController::class, 'edit'])->whereUuid('subject')->name('subjects.edit');
                Route::put('subjects/{subject}', [SubjectController::class, 'update'])->whereUuid('subject')->name('subjects.update');

                Route::get('staff/create', [StaffController::class, 'create'])->name('staff.create');
                Route::post('staff', [StaffController::class, 'store'])->name('staff.store');
                Route::get('staff/{staff}/edit', [StaffController::class, 'edit'])->whereUuid('staff')->name('staff.edit');
                Route::put('staff/{staff}', [StaffController::class, 'update'])->whereUuid('staff')->name('staff.update');
            });

            // Admissions and student records.
            Route::middleware('can:school.students.manage')->group(function () {
                Route::get('applications/create', [ApplicationController::class, 'create'])->name('applications.create');
                Route::post('applications', [ApplicationController::class, 'store'])->name('applications.store');
                Route::post('applications/{application}/admit', [ApplicationController::class, 'admit'])->whereUuid('application')->name('applications.admit');
                Route::post('applications/{application}/decline', [ApplicationController::class, 'decline'])->whereUuid('application')->name('applications.decline');

                Route::get('students/create', [StudentController::class, 'create'])->name('students.create');
                Route::post('students', [StudentController::class, 'store'])->name('students.store');
                Route::get('students/{student}/edit', [StudentController::class, 'edit'])->whereUuid('student')->name('students.edit');
                Route::put('students/{student}', [StudentController::class, 'update'])->whereUuid('student')->name('students.update');

                Route::post('classes/{class}/promote', [ClassController::class, 'promote'])->whereUuid('class')->name('classes.promote');
            });

            Route::middleware('can:school.students.view')->group(function () {
                Route::get('applications', [ApplicationController::class, 'index'])->name('applications.index');
                Route::get('applications/{application}', [ApplicationController::class, 'show'])->whereUuid('application')->name('applications.show');
                Route::get('students', [StudentController::class, 'index'])->name('students.index');
                Route::get('students/{student}', [StudentController::class, 'show'])->whereUuid('student')->name('students.show');
                Route::get('classes', [ClassController::class, 'index'])->name('classes.index');
                Route::get('classes/{class}', [ClassController::class, 'show'])->whereUuid('class')->name('classes.show');
                Route::get('programmes', [ProgrammeController::class, 'index'])->name('programmes.index');
                Route::get('departments', [DepartmentController::class, 'index'])->name('departments.index');
                Route::get('subjects', [SubjectController::class, 'index'])->name('subjects.index');
                Route::get('staff', [StaffController::class, 'index'])->name('staff.index');
                Route::get('attendance', [AttendanceController::class, 'index'])->name('attendance.index');
            });

            // Registers, marks and results.
            Route::post('attendance', [AttendanceController::class, 'store'])
                ->can('school.attendance.record')
                ->name('attendance.store');
            Route::middleware('can:school.marks.record')->group(function () {
                Route::get('marks', [MarkController::class, 'index'])->name('marks.index');
                Route::post('marks', [MarkController::class, 'store'])->name('marks.store');
            });
            Route::middleware('can:school.results.view')->group(function () {
                Route::get('results', [ResultController::class, 'index'])->name('results.index');
                Route::get('students/{student}/report', [ResultController::class, 'show'])->whereUuid('student')->name('results.show');
            });

            // Fees.
            Route::middleware('can:school.fees.manage')->group(function () {
                Route::post('fees/items', [FeeController::class, 'storeItem'])->name('fees.items.store');
                Route::delete('fees/items/{item}', [FeeController::class, 'destroyItem'])->whereUuid('item')->name('fees.items.destroy');
                Route::post('fees/bill', [FeeController::class, 'bill'])->name('fees.bill');
                Route::post('invoices/{invoice}/payments', [InvoiceController::class, 'pay'])->whereUuid('invoice')->name('invoices.payments.store');
            });
            Route::post('invoices/{invoice}/void', [InvoiceController::class, 'void'])
                ->whereUuid('invoice')
                ->can('school.fees.void')
                ->name('invoices.void');
            Route::middleware('can:school.fees.view')->group(function () {
                Route::get('fees', [FeeController::class, 'index'])->name('fees.index');
                Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
                Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->whereUuid('invoice')->name('invoices.show');
            });
        });
    });
