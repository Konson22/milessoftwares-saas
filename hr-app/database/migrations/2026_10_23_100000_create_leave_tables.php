<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leave (HR Phase H3): the kinds of leave an organization gives, each
 * person's days per kind and year, public holidays, and requests.
 *
 * Days are whole working days (Monday to Friday, less public holidays).
 * A balance is never stored: it is the year's entitlement plus what was
 * carried over, less approved requests, so it cannot drift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('name', 100);
            // Starting entitlement for each person and year.
            $table->unsignedSmallInteger('days_per_year');
            $table->boolean('paid');
            // Requests may take the balance below zero (e.g. unpaid leave).
            $table->boolean('allow_negative');
            // Unused days that move into the next year, at most.
            $table->unsignedSmallInteger('max_carry_over');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'name']);
        });

        Schema::create('leave_entitlements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('employee_id');
            $table->uuid('leave_type_id');
            $table->unsignedSmallInteger('year');
            $table->unsignedSmallInteger('days');
            $table->unsignedSmallInteger('carried_days');
            $table->foreignUuid('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['organization_id', 'employee_id', 'leave_type_id', 'year']);
            $table->foreign(['organization_id', 'employee_id'])->references(['organization_id', 'id'])->on('employees')->cascadeOnDelete();
            $table->foreign(['organization_id', 'leave_type_id'])->references(['organization_id', 'id'])->on('leave_types');
        });

        Schema::create('public_holidays', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->date('date');
            $table->string('name', 100);
            $table->timestamps();

            $table->unique(['organization_id', 'date']);
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('employee_id');
            $table->uuid('leave_type_id');
            // Within one calendar year: the year whose balance it uses.
            $table->unsignedSmallInteger('year');
            $table->date('starts_on');
            $table->date('ends_on');
            // Working days between the two, fixed when requested.
            $table->unsignedSmallInteger('days');
            $table->string('reason', 255)->nullable();
            // pending → approved | rejected | cancelled; approved → cancelled
            $table->string('status', 16);
            $table->foreignUuid('requested_by')->constrained('users');
            $table->foreignUuid('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 255)->nullable();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status', 'starts_on']);
            $table->index(['organization_id', 'employee_id', 'year', 'status']);
            $table->foreign(['organization_id', 'employee_id'])->references(['organization_id', 'id'])->on('employees')->cascadeOnDelete();
            $table->foreign(['organization_id', 'leave_type_id'])->references(['organization_id', 'id'])->on('leave_types');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('public_holidays');
        Schema::dropIfExists('leave_entitlements');
        Schema::dropIfExists('leave_types');
    }
};
