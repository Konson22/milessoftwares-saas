<?php

use App\Http\Controllers\AuditController;
use App\Http\Controllers\DataController;
use App\Http\Controllers\FrameworkController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\IndicatorController;
use App\Http\Controllers\ProgrammeController;
use App\Http\Controllers\ProgrammeSetupController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SubmissionController;
use App\Http\Controllers\VerificationController;
use App\Http\Middleware\RememberMember;
use Illuminate\Support\Facades\Route;
use Miles\PlatformClient\PlatformConfig;

/*
|--------------------------------------------------------------------------
| M&E pages
|--------------------------------------------------------------------------
|
| Served at {workspace}/mel. The "platform" middleware resolves the
| workspace from the host and requires a signed-in member whose platform
| access allows M&E. Each route then checks its catalog permission; the
| controllers check the programme, unit and period (App\Mel\Access), and
| answer 404 for anything outside them.
|
*/

if (! app(PlatformConfig::class)->configured()) {
    return;
}

Route::prefix(app(PlatformConfig::class)->path())
    ->middleware(['web', 'platform', RememberMember::class])
    ->group(function () {
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

        // Data collection
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
    });
