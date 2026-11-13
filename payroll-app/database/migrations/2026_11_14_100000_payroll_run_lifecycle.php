<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll Phase 1, the rest of a run's life:
 *
 *   draft -> reviewed (optional) -> approved -> part_paid -> paid
 *                                      \-> reversed (nothing paid yet)
 *
 * The run keeps the currency it was prepared in; payments are recorded per
 * payslip, in batches; a reversed run frees its month for a new one.
 * Payslips stay as they were: the pgsql trigger still locks them once the
 * run leaves draft.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->char('currency', 3)->nullable()->after('status');
            $table->foreignUuid('reviewed_by')->nullable()->after('prepared_at')->constrained('users');
            $table->timestampTz('reviewed_at')->nullable()->after('reviewed_by');
            $table->bigInteger('paid_minor')->default(0)->after('net_minor');
            $table->foreignUuid('reversed_by')->nullable()->constrained('users');
            $table->timestampTz('reversed_at')->nullable();
            $table->string('reversal_reason')->nullable();
        });

        // One live payroll per month: a reversed one no longer counts.
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'period_start']);
        });
        DB::statement("CREATE UNIQUE INDEX payroll_runs_one_per_month ON payroll_runs (organization_id, period_start) WHERE status <> 'reversed'");

        Schema::create('payroll_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->foreignUuid('payroll_run_id')->constrained('payroll_runs');
            // PR-00001-P1, PR-00001-P2...: Finance's reference for the batch.
            $table->string('number', 64);
            $table->string('method', 16);
            $table->date('paid_on');
            // The bank batch, cheque or mobile money transaction, if any.
            $table->string('reference', 100)->nullable();
            $table->bigInteger('amount_minor');
            $table->integer('headcount');
            $table->foreignUuid('recorded_by')->constrained('users');
            $table->timestamps();

            $table->unique(['organization_id', 'number']);
        });

        Schema::create('payslip_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->foreignUuid('payroll_payment_id')->constrained('payroll_payments');
            $table->foreignUuid('payslip_id')->constrained('payslips');
            $table->bigInteger('amount_minor');
            $table->timestamps();

            // Each payslip is paid once.
            $table->unique('payslip_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslip_payments');
        Schema::dropIfExists('payroll_payments');

        DB::statement('DROP INDEX IF EXISTS payroll_runs_one_per_month');
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->unique(['organization_id', 'period_start']);
        });

        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['currency', 'reviewed_at', 'paid_minor', 'reversed_at', 'reversal_reason']);
        });
    }
};
