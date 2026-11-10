<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll (ADR-0008): its own register of people it pays, their pay, the
 * organization's statutory rules, runs and payslips. Money is integer minor
 * units of the workspace currency.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Gap-free running numbers per organization (P-00001, PR-00001).
        Schema::create('document_numbers', function (Blueprint $table) {
            $table->uuid('organization_id');
            $table->string('type', 32);
            $table->unsignedInteger('last_number');
            $table->primary(['organization_id', 'type']);
        });

        // Everyone Payroll pays. Entered here, or kept in step by HR or a
        // product through events (source_app + source_reference).
        Schema::create('employees', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->string('employee_number', 32);
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('work_email')->nullable();
            $table->uuid('branch_id')->nullable();
            $table->uuid('department_id')->nullable();
            $table->string('job_title')->nullable();
            $table->string('pay_basis', 16)->default('monthly'); // monthly | hourly
            $table->string('status', 16)->default('active');
            $table->date('hired_on');
            $table->date('left_on')->nullable();
            // Bank or mobile money account to pay into: encrypted.
            $table->text('payment_details')->nullable();
            $table->string('source_app', 32)->nullable();
            $table->string('source_reference', 64)->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'employee_number']);
            $table->unique(['organization_id', 'source_app', 'source_reference']);
            $table->index(['organization_id', 'status']);
        });

        // Basic pay from a date: a monthly salary, or an hourly rate.
        // Append-only: a change is a new row.
        Schema::create('employee_pay', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->foreignUuid('employee_id')->constrained('employees');
            $table->date('effective_from');
            $table->bigInteger('amount_minor');
            $table->string('note')->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();

            $table->unique(['employee_id', 'effective_from']);
        });

        // Allowances and deductions the organization uses.
        Schema::create('pay_components', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->string('name', 100);
            $table->string('kind', 16); // earning | deduction
            $table->string('calculation', 16); // fixed | percent
            $table->boolean('taxable')->default(true);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });

        // A component paid to or taken from one person, for a time.
        Schema::create('employee_components', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->foreignUuid('employee_id')->constrained('employees');
            $table->foreignUuid('pay_component_id')->constrained('pay_components');
            $table->bigInteger('amount_minor')->nullable(); // fixed
            $table->unsignedInteger('rate_bp')->nullable(); // percent of basic, in basis points
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->timestamps();
        });

        // Income tax bands and social security, from a date: data, not code.
        Schema::create('statutory_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->date('effective_from');
            $table->string('name', 100);
            // [{"up_to_minor": 2000000|null, "rate_bp": 500}, ...] a month, ascending.
            $table->json('income_tax_bands');
            $table->unsignedInteger('social_security_employee_bp')->default(0);
            $table->unsignedInteger('social_security_employer_bp')->default(0);
            $table->bigInteger('social_security_cap_minor')->nullable();
            // Employee contribution taken off taxable pay before tax.
            $table->boolean('social_security_deductible')->default(false);
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();

            $table->unique(['organization_id', 'effective_from']);
        });

        // Hours sent by products (payroll.timesheet.submitted) or entered here.
        Schema::create('timesheet_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->foreignUuid('employee_id')->constrained('employees');
            $table->date('work_date');
            $table->decimal('hours', 5, 2);
            $table->string('kind', 16); // regular | overtime | leave | holiday
            $table->uuid('branch_id')->nullable();
            $table->string('source_app', 32);
            $table->string('source_reference', 128);
            $table->timestamps();

            $table->index(['organization_id', 'employee_id', 'work_date']);
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->string('number', 32);
            $table->date('period_start');
            $table->date('period_end');
            $table->date('pay_date');
            $table->string('status', 16); // draft | approved | paid
            $table->unsignedInteger('headcount')->default(0);
            $table->bigInteger('earnings_minor')->default(0);
            $table->bigInteger('income_tax_minor')->default(0);
            $table->bigInteger('social_security_employee_minor')->default(0);
            $table->bigInteger('social_security_employer_minor')->default(0);
            $table->bigInteger('other_deductions_minor')->default(0);
            $table->bigInteger('net_minor')->default(0);
            $table->foreignUuid('prepared_by')->constrained('users');
            $table->timestampTz('prepared_at');
            $table->foreignUuid('approved_by')->nullable()->constrained('users');
            $table->timestampTz('approved_at')->nullable();
            $table->foreignUuid('paid_by')->nullable()->constrained('users');
            $table->timestampTz('paid_at')->nullable();
            $table->string('payment_method', 16)->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'number']);
            // One payroll per month: prepare() checks first, this stops a race.
            $table->unique(['organization_id', 'period_start']);
        });

        Schema::create('payslips', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->foreignUuid('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained('employees');
            // The person as they were on the run.
            $table->string('employee_number', 32);
            $table->string('employee_name');
            $table->string('job_title')->nullable();
            $table->uuid('branch_id')->nullable();
            $table->uuid('department_id')->nullable();
            $table->decimal('days_paid', 5, 2)->nullable();
            $table->decimal('hours_paid', 7, 2)->nullable();
            $table->bigInteger('basic_minor');
            $table->bigInteger('earnings_minor');
            $table->bigInteger('taxable_minor');
            $table->bigInteger('income_tax_minor');
            $table->bigInteger('social_security_employee_minor');
            $table->bigInteger('social_security_employer_minor');
            $table->bigInteger('other_deductions_minor');
            $table->bigInteger('net_minor');
            // [{"kind": "earning"|"deduction"|"employer", "name": "...", "amount_minor": 0}]
            $table->json('lines');
            $table->timestamps();

            $table->unique(['payroll_run_id', 'employee_id']);
        });

        // Events from HR and products, applied once each
        // (packages/shared-module-kit/schema/postgres.sql).
        Schema::create('shared_module_inbox', function (Blueprint $table) {
            $table->uuid('event_id')->primary();
            $table->string('source_app', 32);
            $table->uuid('organization_id');
            $table->string('source_transaction_id', 128);
            $table->string('event_type', 100);
            $table->smallInteger('schema_version');
            $table->char('content_hash', 64);
            $table->json('payload');
            $table->timestampTz('occurred_at');
            $table->timestampTz('received_at')->useCurrent();
            $table->timestampTz('processed_at')->nullable();

            $table->unique(['source_app', 'organization_id', 'source_transaction_id'], 'shared_module_inbox_source_transaction_unique');
            $table->index(['organization_id', 'received_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            // An approved or paid run's payslips never change.
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION payslips_locked() RETURNS trigger AS $$
                DECLARE run_status varchar;
                BEGIN
                    SELECT status INTO run_status FROM payroll_runs WHERE id = COALESCE(OLD.payroll_run_id, NEW.payroll_run_id);
                    IF run_status IS NOT NULL AND run_status <> 'draft' THEN
                        RAISE EXCEPTION 'payslips of an approved payroll run never change';
                    END IF;
                    RETURN COALESCE(NEW, OLD);
                END;
                $$ LANGUAGE plpgsql
            SQL);
            DB::statement('CREATE TRIGGER payslips_locked BEFORE INSERT OR UPDATE OR DELETE ON payslips FOR EACH ROW EXECUTE FUNCTION payslips_locked()');
            DB::statement('ALTER TABLE payroll_runs ADD CONSTRAINT payroll_runs_balanced CHECK (earnings_minor = net_minor + income_tax_minor + social_security_employee_minor + other_deductions_minor)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS payslips_locked ON payslips');
            DB::statement('DROP FUNCTION IF EXISTS payslips_locked()');
        }

        Schema::dropIfExists('shared_module_inbox');
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('timesheet_entries');
        Schema::dropIfExists('statutory_rules');
        Schema::dropIfExists('employee_components');
        Schema::dropIfExists('pay_components');
        Schema::dropIfExists('employee_pay');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('document_numbers');
    }
};
