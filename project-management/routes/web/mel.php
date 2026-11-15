<?php

use App\Http\Controllers\Mel\AuditController;
use App\Http\Controllers\Mel\Collect\AssignmentController;
use App\Http\Controllers\Mel\Collect\FormController;
use App\Http\Controllers\Mel\DataController;
use App\Http\Controllers\Mel\FrameworkController;
use App\Http\Controllers\Mel\HomeController;
use App\Http\Controllers\Mel\ImportController;
use App\Http\Controllers\Mel\IndicatorController;
use App\Http\Controllers\Mel\ProgrammeController;
use App\Http\Controllers\Mel\ProgrammeSetupController;
use App\Http\Controllers\Mel\ReportController;
use App\Http\Controllers\Mel\SettingsController;
use App\Http\Controllers\Mel\SubmissionController;
use App\Http\Controllers\Mel\VerificationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Monitoring, evaluation and learning, with data collection (ADR-0013)
|--------------------------------------------------------------------------
|
| {workspace}/projects/mel/..., inside the workspace middleware
| (routes/web.php). Each route checks its catalog permission; the
| controllers check the programme, unit and period (App\Mel\Access) or the
| form (App\Collect\Scope), and answer 404 for anything outside them.
|
*/

Route::prefix('mel')->name('mel.')->group(function () {
    Route::get('/', HomeController::class)->name('home');

    // Programmes
    Route::middleware('can:mel.programmes.view')->group(function () {
        Route::get('programmes', [ProgrammeController::class, 'index'])->name('programmes.index');
        Route::get('programmes/create', [ProgrammeController::class, 'create'])->middleware('can:mel.programmes.manage')->name('programmes.create');
        Route::post('programmes', [ProgrammeController::class, 'store'])->middleware('can:mel.programmes.manage')->name('programmes.store');
        Route::get('programmes/{programme}', [ProgrammeController::class, 'show'])->name('programmes.show');
    });

    Route::prefix('programmes/{programme}')->middleware('can:mel.programmes.manage')->group(function () {
        Route::get('edit', [ProgrammeController::class, 'edit'])->name('programmes.edit');
        Route::put('/', [ProgrammeController::class, 'update'])->name('programmes.update');
        Route::post('archive', [ProgrammeController::class, 'archive'])->name('programmes.archive');
        Route::post('units', [ProgrammeSetupController::class, 'storeUnit'])->name('units.store');
        Route::put('units/{unit}', [ProgrammeSetupController::class, 'updateUnit'])->name('units.update');
        Route::post('units/{unit}/archive', [ProgrammeSetupController::class, 'archiveUnit'])->name('units.archive');
        Route::post('periods/generate', [ProgrammeSetupController::class, 'generatePeriods'])->name('periods.generate');
        Route::post('periods', [ProgrammeSetupController::class, 'storePeriod'])->name('periods.store');
        Route::put('periods/{period}/status', [ProgrammeSetupController::class, 'periodStatus'])->name('periods.status');
        Route::post('access', [ProgrammeSetupController::class, 'grant'])->name('access.store');
        Route::delete('access/{grant}', [ProgrammeSetupController::class, 'revoke'])->name('access.destroy');
    });
    Route::put('programmes/{programme}/units/{unit}/project', [ProgrammeSetupController::class, 'linkProject'])
        ->middleware('can:mel.projects.link')->name('units.project');

    // Results frameworks
    Route::middleware('can:mel.programmes.view')->group(function () {
        Route::get('frameworks', [FrameworkController::class, 'index'])->name('frameworks.index');
        Route::get('programmes/{programme}/framework', [FrameworkController::class, 'show'])->name('frameworks.show');
    });
    Route::prefix('programmes/{programme}')->middleware('can:mel.frameworks.manage')->group(function () {
        Route::put('framework/levels', [FrameworkController::class, 'levels'])->name('frameworks.levels');
        Route::post('results', [FrameworkController::class, 'storeResult'])->name('results.store');
        Route::put('results/{result}', [FrameworkController::class, 'updateResult'])->name('results.update');
        Route::post('results/{result}/archive', [FrameworkController::class, 'archiveResult'])->name('results.archive');
        Route::post('results/{result}/documents', [FrameworkController::class, 'attach'])->name('results.documents');
    });

    // Indicators
    Route::middleware('can:mel.programmes.view')->group(function () {
        Route::get('indicators', [IndicatorController::class, 'index'])->name('indicators.index');
        Route::get('programmes/{programme}/indicators/create', [IndicatorController::class, 'create'])->middleware('can:mel.frameworks.manage')->name('indicators.create');
        Route::get('programmes/{programme}/indicators/{indicator}', [IndicatorController::class, 'show'])->name('indicators.show');
    });
    Route::prefix('programmes/{programme}/indicators')->middleware('can:mel.frameworks.manage')->group(function () {
        Route::post('/', [IndicatorController::class, 'store'])->name('indicators.store');
        Route::get('{indicator}/edit', [IndicatorController::class, 'edit'])->name('indicators.edit');
        Route::put('{indicator}', [IndicatorController::class, 'update'])->name('indicators.update');
        Route::put('{indicator}/status', [IndicatorController::class, 'status'])->name('indicators.status');
        Route::post('{indicator}/baseline', [IndicatorController::class, 'baseline'])->name('indicators.baseline');
        Route::put('{indicator}/targets', [IndicatorController::class, 'targets'])->name('indicators.targets');
    });

    // Results data
    Route::middleware('can:mel.data.view')->group(function () {
        Route::get('data', [DataController::class, 'index'])->name('data.index');
    });
    Route::middleware('can:mel.data.enter')->group(function () {
        Route::get('programmes/{programme}/periods/{period}/indicators/{indicator}/units/{unit}', [DataController::class, 'entry'])->name('data.entry');
        Route::post('programmes/{programme}/periods/{period}/indicators/{indicator}/units/{unit}', [DataController::class, 'save'])->name('data.save');
        Route::post('submissions/{submission}/submit', [SubmissionController::class, 'submit'])->name('submissions.submit');
        Route::delete('submissions/{submission}', [SubmissionController::class, 'discard'])->name('submissions.discard');
        Route::post('submissions/{submission}/evidence', [SubmissionController::class, 'attach'])->name('submissions.evidence');
    });
    Route::delete('evidence/{evidence}', [SubmissionController::class, 'detach'])->name('evidence.destroy');
    Route::get('evidence/{evidence}', [SubmissionController::class, 'download'])->name('evidence.download');

    Route::middleware('can:mel.data.import')->prefix('programmes/{programme}/import')->group(function () {
        Route::get('/', [ImportController::class, 'show'])->name('import.show');
        Route::get('template/{period}', [ImportController::class, 'template'])->name('import.template');
        Route::post('/', [ImportController::class, 'store'])->name('import.store');
        Route::post('{batch}/apply', [ImportController::class, 'apply'])->name('import.apply');
    });

    // Verification
    Route::get('submissions/{submission}', [SubmissionController::class, 'show'])->name('submissions.show');
    Route::middleware('can:mel.data.review')->group(function () {
        Route::get('verification', [VerificationController::class, 'index'])->name('verification.index');
        Route::post('submissions/{submission}/review', [SubmissionController::class, 'startReview'])->name('submissions.review');
        Route::post('submissions/{submission}/verify', [SubmissionController::class, 'verify'])->name('submissions.verify');
        Route::post('submissions/{submission}/reject', [SubmissionController::class, 'reject'])->name('submissions.reject');
    });
    Route::middleware('can:mel.results.publish')->group(function () {
        Route::post('submissions/{submission}/publish', [SubmissionController::class, 'publish'])->name('submissions.publish');
        Route::post('verification/publish', [VerificationController::class, 'publish'])->name('verification.publish');
    });

    // Reports
    Route::middleware('can:mel.reports.view')->group(function () {
        Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('reports/{report}', [ReportController::class, 'show'])->name('reports.show');
    });

    // Settings and audit
    Route::middleware('can:mel.settings.manage')->prefix('settings')->group(function () {
        Route::get('/', [SettingsController::class, 'show'])->name('settings.show');
        Route::put('/', [SettingsController::class, 'update'])->name('settings.update');
        Route::post('locations/{location?}', [SettingsController::class, 'saveLocation'])->name('settings.locations');
        Route::post('partners/{partner?}', [SettingsController::class, 'savePartner'])->name('settings.partners');
        Route::post('dimensions/{dimension?}', [SettingsController::class, 'saveDimension'])->name('settings.dimensions');
    });
    Route::get('audit', AuditController::class)->middleware('can:mel.audit.view')->name('audit.index');

    // Data collection (ADR-0012)
    Route::prefix('collect')->name('collect.')->group(function () {
        // Forms & surveys
        Route::middleware('can:mel.collect.view')->group(function () {
            Route::get('forms', [FormController::class, 'index'])->name('forms.index');
            Route::get('forms/create', [FormController::class, 'create'])->middleware('can:mel.collect.design')->name('forms.create');
            Route::get('forms/{form}', [FormController::class, 'show'])->name('forms.show');
            Route::get('forms/{form}/versions/{version}', [FormController::class, 'version'])->name('forms.version');
        });

        Route::middleware('can:mel.collect.design')->group(function () {
            Route::post('forms', [FormController::class, 'store'])->name('forms.store');
            Route::get('forms/{form}/edit', [FormController::class, 'edit'])->name('forms.edit');
            Route::put('forms/{form}', [FormController::class, 'update'])->name('forms.update');
            Route::get('forms/{form}/build', [FormController::class, 'build'])->name('forms.build');
            Route::put('forms/{form}/draft', [FormController::class, 'saveDraft'])->name('forms.draft');
            Route::post('forms/{form}/duplicate', [FormController::class, 'duplicate'])->name('forms.duplicate');
        });

        Route::middleware('can:mel.collect.publish')->group(function () {
            Route::post('forms/{form}/publish', [FormController::class, 'publish'])->name('forms.publish');
            Route::post('forms/{form}/archive', [FormController::class, 'archive'])->name('forms.archive');
            Route::post('forms/{form}/restore', [FormController::class, 'restore'])->name('forms.restore');
            Route::post('forms/{form}/versions/{version}/close', [FormController::class, 'closeVersion'])->name('forms.versions.close');
        });

        // Field assignments
        Route::get('assignments', [AssignmentController::class, 'index'])->middleware('can:mel.collect.view')->name('assignments.index');
        Route::middleware('can:mel.collect.assign')->group(function () {
            Route::post('forms/{form}/assignments', [AssignmentController::class, 'store'])->name('assignments.store');
            Route::post('assignments/{assignment}/close', [AssignmentController::class, 'close'])->name('assignments.close');
            Route::post('assignments/{assignment}/revoke', [AssignmentController::class, 'revoke'])->name('assignments.revoke');
        });
    });
});
