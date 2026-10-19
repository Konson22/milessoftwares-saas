<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff records (HR Phase H1). Branch, department and position ids are the
 * platform's (Structure owns them); HR keeps no copy of their names.
 *
 * Children point at their employee through (organization_id, employee_id),
 * so a row can never belong to an employee of another tenant. Personal
 * details live in their own table, encrypted, so work queries never load them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_numbers', function (Blueprint $table) {
            $table->uuid('organization_id');
            $table->string('type', 16);
            $table->unsignedBigInteger('last_number');

            $table->primary(['organization_id', 'type']);
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('employee_number', 32);
            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100);
            $table->string('preferred_name', 100)->nullable();
            $table->string('work_email', 255)->nullable();
            $table->string('work_phone', 32)->nullable();
            // Where they work now: the latest employment record.
            $table->uuid('branch_id')->nullable();
            $table->uuid('department_id')->nullable();
            $table->uuid('position_id')->nullable();
            $table->string('job_title', 255)->nullable();
            $table->uuid('manager_id')->nullable();
            // The current contract's type.
            $table->string('employment_type', 16);
            // active → left
            $table->string('status', 16);
            $table->date('hired_on');
            $table->date('left_on')->nullable();
            $table->string('leaving_reason', 16)->nullable();
            // Platform membership, when the person also signs in.
            $table->uuid('membership_id')->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();

            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'employee_number']);
            $table->index(['organization_id', 'status', 'last_name']);
            $table->index(['organization_id', 'branch_id']);
            $table->index(['organization_id', 'department_id']);

            $table->foreign(['organization_id', 'manager_id'])->references(['organization_id', 'id'])->on('employees');
        });

        Schema::create('employee_personal_details', function (Blueprint $table) {
            $table->uuid('employee_id')->primary();
            $table->uuid('organization_id');
            // Encrypted with the application key (an array cast); never queried.
            $table->text('details');
            $table->foreignUuid('updated_by')->constrained('users');
            $table->timestamps();

            $table->foreign(['organization_id', 'employee_id'])->references(['organization_id', 'id'])->on('employees')->cascadeOnDelete();
        });

        // Append-only: what changed in someone's employment, and from when.
        Schema::create('employment_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('employee_id');
            // hired | transfer | promotion | change | left
            $table->string('kind', 16);
            $table->date('effective_on');
            // The assignment from then on.
            $table->uuid('branch_id')->nullable();
            $table->uuid('department_id')->nullable();
            $table->uuid('position_id')->nullable();
            $table->string('job_title', 255)->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignUuid('recorded_by')->constrained('users');
            $table->timestamps();

            $table->index(['organization_id', 'employee_id', 'effective_on']);
            $table->foreign(['organization_id', 'employee_id'])->references(['organization_id', 'id'])->on('employees')->cascadeOnDelete();
        });

        Schema::create('employment_contracts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('employee_id');
            // permanent | fixed_term | casual | internship | consultancy
            $table->string('contract_type', 16);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->date('probation_ends_on')->nullable();
            $table->unsignedSmallInteger('notice_days')->nullable();
            $table->string('note', 255)->nullable();
            // current → ended (a newer contract, or leaving)
            $table->string('status', 16);
            $table->date('ended_on')->nullable();
            $table->foreignUuid('recorded_by')->constrained('users');
            $table->timestamps();

            $table->index(['organization_id', 'employee_id', 'status']);
            $table->index(['organization_id', 'status', 'ends_on']);
            $table->foreign(['organization_id', 'employee_id'])->references(['organization_id', 'id'])->on('employees')->cascadeOnDelete();
        });

        // Who looked at or changed sensitive records: field names, never values.
        Schema::create('hr_audit_log', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('actor_id')->constrained('users');
            $table->string('action', 64);
            $table->uuid('employee_id')->nullable();
            $table->json('fields')->nullable();
            $table->timestamp('created_at');

            $table->index(['organization_id', 'employee_id', 'created_at']);
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_audit_log');
        Schema::dropIfExists('employment_contracts');
        Schema::dropIfExists('employment_records');
        Schema::dropIfExists('employee_personal_details');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('document_numbers');
    }
};
