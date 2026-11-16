<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * ADR-0019 §24: who a unit's published results are released to in its
 * linked project's partner reports. Additive; every existing unit starts at
 * "owner", which is what it had (the project owner's members only).
 *
 *   owner    the project owner's members
 *   partner  also the named partner organization, on reports it submits,
 *            and the party reviewing them
 *   donors   also donors, on published reports
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mel_programme_units', function (Blueprint $table) {
            $table->string('project_report_audience', 16)->default('owner')->after('shares_with_project_reports');
            $table->uuid('project_report_partner_organization_id')->nullable()->after('project_report_audience');
            $table->string('project_report_partner_name')->nullable()->after('project_report_partner_organization_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE mel_programme_units ADD CONSTRAINT mel_units_report_audience CHECK (project_report_audience IN ('owner', 'partner', 'donors'))");
            DB::statement("ALTER TABLE mel_programme_units ADD CONSTRAINT mel_units_report_partner CHECK (project_report_audience <> 'partner' OR project_report_partner_organization_id IS NOT NULL)");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE mel_programme_units DROP CONSTRAINT IF EXISTS mel_units_report_partner');
            DB::statement('ALTER TABLE mel_programme_units DROP CONSTRAINT IF EXISTS mel_units_report_audience');
        }
        Schema::table('mel_programme_units', function (Blueprint $table) {
            $table->dropColumn(['project_report_audience', 'project_report_partner_organization_id', 'project_report_partner_name']);
        });
    }
};
