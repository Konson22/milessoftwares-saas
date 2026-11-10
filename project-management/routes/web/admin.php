<?php

use App\Http\Controllers\Admin\ApprovalPolicyController;
use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\MemberController;
use App\Http\Controllers\Admin\OrgUnitController;
use App\Http\Controllers\Admin\SettingsController;
use App\Support\Modules;
use Illuminate\Support\Facades\Route;

/*
| Administration of the organization in Projects (inviting and roles are
| on the platform): members' units, org units, settings, approval rules, audit log. Inside an organization (routes/web.php).
*/

Route::prefix('admin')->name('admin.')->group(function () {
    Route::middleware('can:organization.manage')->group(function () {
        Route::get('members', [MemberController::class, 'index'])->name('members.index');
        Route::put('members/{membership}/unit', [MemberController::class, 'updateUnit'])->whereUuid('membership')->name('members.unit');

        Route::get('org-units', [OrgUnitController::class, 'index'])->name('org-units.index');
        Route::post('org-units', [OrgUnitController::class, 'store'])->name('org-units.store');
        Route::put('org-units/{orgUnit}', [OrgUnitController::class, 'update'])->whereUuid('orgUnit')->name('org-units.update');
        Route::post('org-units/{orgUnit}/archive', [OrgUnitController::class, 'archive'])->whereUuid('orgUnit')->name('org-units.archive');
        Route::post('org-units/{orgUnit}/restore', [OrgUnitController::class, 'restore'])->whereUuid('orgUnit')->name('org-units.restore');

        Route::get('settings', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::put('settings/health', [SettingsController::class, 'updateHealth'])->name('settings.health');
        Route::put('settings/finance-limits', [SettingsController::class, 'updateFinanceLimits'])->name('settings.finance-limits');
        Route::put('settings/funding', [SettingsController::class, 'updateFunding'])->name('settings.funding');
        Route::put('settings/modules/{module}', [SettingsController::class, 'updateModule'])->whereIn('module', Modules::keys())->name('settings.modules');
        Route::post('approval-policies', [ApprovalPolicyController::class, 'store'])->name('approval-policies.store');
        Route::delete('approval-policies/{policy}', [ApprovalPolicyController::class, 'destroy'])->whereUuid('policy')->name('approval-policies.destroy');
    });

    Route::get('audit', [AuditController::class, 'index'])->can('organization.audit.view')->name('audit.index');
});
