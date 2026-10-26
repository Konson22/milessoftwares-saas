<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Gap-free running numbers per organization (App\Support\DocumentNumbers).
        Schema::create('document_numbers', function (Blueprint $table) {
            $table->uuid('organization_id');
            $table->string('type', 32);
            $table->unsignedBigInteger('last_number');
            $table->primary(['organization_id', 'type']);
        });

        // The calendar: academic years split into terms (primary) or semesters.
        Schema::create('academic_years', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('name', 32);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamps();
            $table->unique(['organization_id', 'name']);
        });

        Schema::create('terms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->foreignUuid('academic_year_id')->constrained();
            $table->string('name', 32);
            $table->unsignedTinyInteger('sequence');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamps();
        });

        // Colleges and universities: faculties (universities) hold
        // departments, departments offer programmes.
        Schema::create('faculties', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->string('name');
            $table->string('code', 16)->nullable();
            $table->timestamps();
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->foreignUuid('faculty_id')->nullable()->constrained();
            $table->string('name');
            $table->string('code', 16)->nullable();
            $table->timestamps();
        });

        Schema::create('programmes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('department_id')->nullable()->constrained();
            $table->string('code', 16);
            $table->string('name');
            $table->string('award', 32);
            $table->unsignedTinyInteger('duration_years');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
        });

        // Teachers, instructors, lecturers and other staff.
        Schema::create('staff', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('number', 32);
            $table->string('name');
            $table->string('position', 64);
            $table->foreignUuid('department_id')->nullable()->constrained();
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->date('started_on');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'number']);
        });

        // Where students sit: "Primary 4 East", or a programme's year of
        // study ("Diploma in Nursing, Year 2").
        Schema::create('school_classes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->string('name', 64);
            $table->unsignedTinyInteger('level');
            $table->foreignUuid('programme_id')->nullable()->constrained();
            $table->foreignUuid('class_teacher_id')->nullable()->constrained('staff');
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // Subjects (primary), modules (institutes) or courses.
        Schema::create('subjects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('code', 16);
            $table->string('name');
            $table->unsignedTinyInteger('credit_hours')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
        });

        // What each class is taught, and by whom.
        Schema::create('class_subjects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('school_class_id')->constrained();
            $table->foreignUuid('subject_id')->constrained();
            $table->foreignUuid('teacher_id')->nullable()->constrained('staff');
            $table->timestamps();
            $table->unique(['school_class_id', 'subject_id']);
        });

        Schema::create('students', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('number', 32);
            $table->string('first_name');
            $table->string('last_name');
            $table->string('gender', 8);
            $table->date('date_of_birth')->nullable();
            $table->foreignUuid('school_class_id')->nullable()->constrained();
            $table->string('status', 16);
            $table->date('admitted_on');
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('guardian_name')->nullable();
            $table->string('guardian_phone', 32)->nullable();
            $table->string('guardian_relationship', 32)->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'status']);
        });

        // Admissions: an application, then admitted (a student) or declined.
        Schema::create('applications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('number', 32);
            $table->string('first_name');
            $table->string('last_name');
            $table->string('gender', 8);
            $table->date('date_of_birth')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('guardian_name')->nullable();
            $table->string('guardian_phone', 32)->nullable();
            $table->string('previous_school')->nullable();
            $table->unsignedTinyInteger('level');
            $table->foreignUuid('programme_id')->nullable()->constrained();
            $table->string('status', 16);
            $table->foreignUuid('received_by')->constrained('users');
            $table->foreignUuid('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note')->nullable();
            $table->foreignUuid('student_id')->nullable()->constrained();
            $table->timestamps();
            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'status']);
        });

        // A register: one class on one day (and, outside primary schools,
        // one subject), with an entry per student.
        Schema::create('attendance_registers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('school_class_id')->constrained();
            $table->foreignUuid('subject_id')->nullable()->constrained();
            $table->foreignUuid('term_id')->constrained();
            $table->date('taken_on');
            $table->foreignUuid('taken_by')->constrained('users');
            $table->timestamps();
            $table->index(['school_class_id', 'taken_on']);
        });

        Schema::create('attendance_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('attendance_register_id')->constrained();
            $table->foreignUuid('student_id')->constrained();
            $table->string('status', 8);
            $table->timestamps();
            $table->unique(['attendance_register_id', 'student_id']);
        });

        // Assessments of a term (mid-term, coursework, final exam...), their
        // weights adding up to 100, and each student's mark out of 100.
        Schema::create('assessments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->foreignUuid('term_id')->constrained();
            $table->string('name', 64);
            $table->unsignedTinyInteger('weight');
            // Order on mark sheets and report cards.
            $table->unsignedTinyInteger('position');
            $table->timestamps();
        });

        Schema::create('marks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('assessment_id')->constrained();
            $table->foreignUuid('subject_id')->constrained();
            $table->foreignUuid('student_id')->constrained();
            $table->decimal('score', 5, 2);
            $table->foreignUuid('recorded_by')->constrained('users');
            $table->timestamps();
            $table->unique(['assessment_id', 'subject_id', 'student_id']);
        });

        // Fees: what each class pays in a term (a null class: every class).
        Schema::create('fee_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->foreignUuid('term_id')->constrained();
            $table->foreignUuid('school_class_id')->nullable()->constrained();
            $table->string('name');
            $table->unsignedBigInteger('amount_minor');
            $table->timestamps();
        });

        // A student's fee invoice for a term, paid in one or more payments.
        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('number', 32);
            $table->foreignUuid('student_id')->constrained();
            $table->foreignUuid('term_id')->nullable()->constrained();
            $table->char('currency', 3);
            $table->unsignedBigInteger('total_minor');
            $table->unsignedBigInteger('paid_minor')->default(0);
            $table->string('status', 16);
            $table->date('issued_on');
            $table->date('due_on')->nullable();
            $table->foreignUuid('issued_by')->constrained('users');
            $table->foreignUuid('voided_by')->nullable()->constrained('users');
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'status']);
            $table->index(['student_id', 'term_id']);
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('invoice_id')->constrained();
            $table->string('description');
            $table->unsignedBigInteger('amount_minor');
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('invoice_id')->constrained();
            $table->string('number', 32);
            $table->unsignedBigInteger('amount_minor');
            $table->string('method', 16);
            $table->string('reference', 128)->nullable();
            $table->date('paid_on');
            $table->foreignUuid('received_by')->constrained('users');
            $table->timestamps();
            $table->unique(['organization_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('fee_items');
        Schema::dropIfExists('marks');
        Schema::dropIfExists('assessments');
        Schema::dropIfExists('attendance_entries');
        Schema::dropIfExists('attendance_registers');
        Schema::dropIfExists('applications');
        Schema::dropIfExists('students');
        Schema::dropIfExists('class_subjects');
        Schema::dropIfExists('subjects');
        Schema::dropIfExists('school_classes');
        Schema::dropIfExists('staff');
        Schema::dropIfExists('programmes');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('faculties');
        Schema::dropIfExists('terms');
        Schema::dropIfExists('academic_years');
        Schema::dropIfExists('document_numbers');
    }
};
