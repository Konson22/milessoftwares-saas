<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who did what in Payroll, and when: runs, pay, payment details and rules.
 * Rows are only ever added (PostgreSQL refuses changes and deletes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_audit_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            // run.approved, employee.pay_set, settings.rule_added...
            $table->string('action', 48);
            $table->string('subject_type', 32);
            $table->uuid('subject_id')->nullable();
            // PR-00001, "Akol Deng (EMP-00001)": readable after the subject is gone.
            $table->string('subject_label');
            // Null for changes from another product's events.
            $table->foreignUuid('user_id')->nullable()->constrained('users');
            $table->string('user_name')->nullable();
            $table->json('details')->nullable();
            $table->timestampTz('created_at');

            $table->index(['organization_id', 'created_at']);
            $table->index(['organization_id', 'subject_type', 'subject_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION payroll_audit_append_only() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'the payroll audit trail is append-only';
                END;
                $$ LANGUAGE plpgsql
            SQL);
            DB::statement('CREATE TRIGGER payroll_audit_append_only BEFORE UPDATE OR DELETE ON payroll_audit_events FOR EACH ROW EXECUTE FUNCTION payroll_audit_append_only()');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS payroll_audit_append_only ON payroll_audit_events');
            DB::statement('DROP FUNCTION IF EXISTS payroll_audit_append_only()');
        }

        Schema::dropIfExists('payroll_audit_events');
    }
};
