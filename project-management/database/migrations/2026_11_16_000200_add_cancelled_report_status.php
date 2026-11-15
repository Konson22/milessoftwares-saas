<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reports of an ended obligation that were never submitted are now
 * "cancelled" (final) instead of "withdrawn", so they neither show as
 * overdue nor can be submitted later. Only the PostgreSQL check constraint
 * changes; existing rows are left as they are. Rolling back turns cancelled
 * reports back into withdrawn ones (the previous behaviour).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE project_reports DROP CONSTRAINT IF EXISTS project_reports_status_check');
            DB::statement("ALTER TABLE project_reports ADD CONSTRAINT project_reports_status_check CHECK (status IN ('draft', 'submitted', 'under_review', 'changes_requested', 'approved', 'published', 'rejected', 'withdrawn', 'cancelled'))");
        }
    }

    public function down(): void
    {
        DB::table('project_reports')->where('status', 'cancelled')->update(['status' => 'withdrawn']);
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE project_reports DROP CONSTRAINT IF EXISTS project_reports_status_check');
            DB::statement("ALTER TABLE project_reports ADD CONSTRAINT project_reports_status_check CHECK (status IN ('draft', 'submitted', 'under_review', 'changes_requested', 'approved', 'published', 'rejected', 'withdrawn'))");
        }
    }
};
