<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-off amounts on one person's payslip for one run: a bonus, arrears,
 * an advance recovered. Added while the run is a draft; the payslip is
 * recalculated with them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('run_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->foreignUuid('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained('employees');
            $table->string('kind', 16);
            $table->string('name', 100);
            $table->bigInteger('amount_minor');
            $table->boolean('taxable')->default(false);
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['payroll_run_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('run_adjustments');
    }
};
