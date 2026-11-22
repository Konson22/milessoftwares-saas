<?php

use App\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Logbook operations (ADR-0026): resources and locations, exceptions,
 * tasks with recurring series, scheduled log sheets with their expected
 * occurrences, work files, notifications and reminders.
 *
 * Additive only: no existing table or column changes. Every table is
 * tenant keyed with composite foreign keys, as in ADR-0025.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('log_locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('parent_id')->nullable();
            $table->string('name', 120);
            $table->boolean('active')->default(true);
            $table->timestamps();
            TenantSchema::key($table);
            $table->index(['organization_id', 'parent_id']);
        });
        Schema::table('log_locations', fn (Blueprint $table) => TenantSchema::foreign($table, 'parent_id', 'log_locations'));

        Schema::create('log_resources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('code', 32);
            $table->string('name', 120);
            // Free text, chosen from what the workspace already uses: Generator, Vehicle…
            $table->string('type', 48);
            $table->text('description')->nullable();
            $table->uuid('location_id')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            TenantSchema::key($table);
            TenantSchema::foreign($table, 'location_id', 'log_locations');
            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'type', 'active']);
        });

        Schema::create('log_exceptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('reference', 32);
            $table->string('title', 160);
            $table->text('description')->nullable();
            // reading (out of normal range), check (a failed answer), manual.
            $table->string('source', 16);
            // low, medium, high, critical.
            $table->string('severity', 16)->default('medium');
            // open, in_progress, resolved, closed.
            $table->string('status', 16)->default('open');
            $table->uuid('logbook_id')->nullable();
            $table->uuid('log_sheet_id')->nullable();
            $table->uuid('log_entry_id')->nullable();
            $table->uuid('resource_id')->nullable();
            // What was recorded, copied as text: the entry itself never changes.
            $table->string('field', 64)->nullable();
            $table->string('field_label', 160)->nullable();
            $table->string('recorded_value', 500)->nullable();
            $table->string('expected', 200)->nullable();
            // "{entry}:{field}" for automatic exceptions: one per entry and field.
            $table->string('auto_key', 120)->nullable();
            $table->foreignUuid('raised_by')->nullable()->constrained('users');
            $table->foreignUuid('assignee_id')->nullable()->constrained('users');
            $table->timestampTz('due_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->foreignUuid('resolved_by')->nullable()->constrained('users');
            $table->timestampTz('closed_at')->nullable();
            $table->foreignUuid('closed_by')->nullable()->constrained('users');
            $table->timestampTz('reopened_at')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            TenantSchema::key($table);
            TenantSchema::foreign($table, 'logbook_id', 'logbooks');
            TenantSchema::foreign($table, 'log_sheet_id', 'log_sheets');
            TenantSchema::foreign($table, 'log_entry_id', 'log_entries');
            TenantSchema::foreign($table, 'resource_id', 'log_resources');
            $table->unique(['organization_id', 'reference']);
            $table->unique(['organization_id', 'auto_key']);
            $table->index(['organization_id', 'status', 'due_at']);
            $table->index(['organization_id', 'assignee_id', 'status']);
            $table->index(['organization_id', 'log_entry_id']);
        });

        Schema::create('log_task_series', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->string('priority', 8)->default('normal');
            $table->uuid('logbook_id')->nullable();
            $table->uuid('log_sheet_id')->nullable();
            $table->uuid('resource_id')->nullable();
            $table->foreignUuid('assignee_id')->constrained('users');
            $table->boolean('verification_required')->default(false);
            $table->boolean('evidence_required')->default(false);
            // daily, weekly (weekdays), monthly (day_of_month; past the month's end: its last day).
            $table->string('frequency', 8);
            $table->json('weekdays')->nullable();
            $table->unsignedTinyInteger('day_of_month')->nullable();
            // Local wall times in the series' time zone, "HH:MM". A due time at or
            // before the start time is on the next day.
            $table->string('start_time', 5);
            $table->string('due_time', 5);
            $table->string('timezone', 64);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            // Minutes relative to the due time: [-30] = 30 minutes before.
            $table->json('reminders')->nullable();
            $table->boolean('active')->default(true);
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();
            TenantSchema::key($table);
            TenantSchema::foreign($table, 'logbook_id', 'logbooks');
            TenantSchema::foreign($table, 'log_sheet_id', 'log_sheets');
            TenantSchema::foreign($table, 'resource_id', 'log_resources');
            $table->index(['organization_id', 'active']);
        });

        Schema::create('log_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('reference', 32);
            $table->string('title', 160);
            $table->text('description')->nullable();
            // low, normal, high, urgent.
            $table->string('priority', 8)->default('normal');
            // pending, in_progress, awaiting_verification, returned, completed, cancelled.
            // Overdue is not a status: it is due_at passed while still open.
            $table->string('status', 24)->default('pending');
            $table->uuid('logbook_id')->nullable();
            $table->uuid('log_sheet_id')->nullable();
            $table->uuid('log_entry_id')->nullable();
            $table->uuid('exception_id')->nullable();
            $table->uuid('resource_id')->nullable();
            $table->uuid('series_id')->nullable();
            $table->date('occurrence_on')->nullable();
            $table->foreignUuid('assignee_id')->constrained('users');
            $table->foreignUuid('created_by')->constrained('users');
            $table->boolean('verification_required')->default(false);
            // Completing needs at least one evidence file.
            $table->boolean('evidence_required')->default(false);
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('due_at')->nullable();
            $table->json('reminders')->nullable();
            $table->timestampTz('assigned_at');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->foreignUuid('completed_by')->nullable()->constrained('users');
            $table->text('completion_notes')->nullable();
            // An entry written as part of the work.
            $table->uuid('completion_entry_id')->nullable();
            // The phone's key for its completion: a repeat answers the first one.
            $table->string('completion_key', 64)->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->foreignUuid('verified_by')->nullable()->constrained('users');
            $table->text('return_comment')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users');
            $table->text('cancel_reason')->nullable();
            $table->timestampTz('reopened_at')->nullable();
            // Up by one on every change: the phone sends the revision it saw.
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            TenantSchema::key($table);
            TenantSchema::foreign($table, 'logbook_id', 'logbooks');
            TenantSchema::foreign($table, 'log_sheet_id', 'log_sheets');
            TenantSchema::foreign($table, 'log_entry_id', 'log_entries');
            TenantSchema::foreign($table, 'completion_entry_id', 'log_entries');
            TenantSchema::foreign($table, 'exception_id', 'log_exceptions');
            TenantSchema::foreign($table, 'resource_id', 'log_resources');
            TenantSchema::foreign($table, 'series_id', 'log_task_series');
            $table->unique(['organization_id', 'reference']);
            $table->unique(['series_id', 'occurrence_on']);
            $table->index(['organization_id', 'assignee_id', 'status']);
            $table->index(['organization_id', 'status', 'due_at']);
            $table->index(['organization_id', 'created_by']);
            $table->index(['organization_id', 'exception_id']);
        });

        Schema::create('log_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('logbook_id');
            $table->uuid('log_sheet_id');
            $table->string('frequency', 8);
            $table->json('weekdays')->nullable();
            $table->unsignedTinyInteger('day_of_month')->nullable();
            // Local wall times, "HH:MM": entries count from opens_time; due by due_time.
            $table->string('opens_time', 5);
            $table->string('due_time', 5);
            // A late entry still counts this long after the due time; after that, missed.
            $table->unsignedInteger('late_minutes')->default(240);
            $table->string('timezone', 64);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            // Null: anyone who writes on the sheet; otherwise that person's entry.
            $table->foreignUuid('responsible_id')->nullable()->constrained('users');
            // submitted (an entry is in) or reviewed (and a reviewer has passed it).
            $table->string('completion', 12)->default('submitted');
            $table->json('reminders')->nullable();
            $table->boolean('active')->default(true);
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();
            TenantSchema::key($table);
            TenantSchema::foreign($table, 'logbook_id', 'logbooks');
            TenantSchema::foreign($table, 'log_sheet_id', 'log_sheets');
            $table->index(['organization_id', 'log_sheet_id']);
            $table->index(['organization_id', 'active']);
        });

        Schema::create('log_schedule_occurrences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('schedule_id');
            $table->uuid('logbook_id');
            $table->uuid('log_sheet_id');
            $table->date('occurs_on');
            $table->timestampTz('opens_at');
            $table->timestampTz('due_at');
            $table->timestampTz('closes_at');
            $table->foreignUuid('responsible_id')->nullable()->constrained('users');
            // open (upcoming or due: by the clock), completed, late, missed, excused.
            $table->string('status', 12)->default('open');
            $table->uuid('log_entry_id')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampTz('settled_at')->nullable();
            $table->foreignUuid('excused_by')->nullable()->constrained('users');
            $table->text('excuse_reason')->nullable();
            $table->timestamps();
            TenantSchema::key($table);
            TenantSchema::foreign($table, 'schedule_id', 'log_schedules');
            TenantSchema::foreign($table, 'logbook_id', 'logbooks');
            TenantSchema::foreign($table, 'log_sheet_id', 'log_sheets');
            TenantSchema::foreign($table, 'log_entry_id', 'log_entries');
            $table->unique(['schedule_id', 'occurs_on']);
            $table->index(['organization_id', 'status', 'closes_at']);
            $table->index(['organization_id', 'log_sheet_id', 'opens_at']);
            $table->index(['organization_id', 'responsible_id', 'status']);
        });

        // Briefing files and completion evidence of tasks; evidence of exceptions.
        Schema::create('log_work_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('task_id')->nullable();
            $table->uuid('exception_id')->nullable();
            // brief (with the task), evidence (of the work or the resolution).
            $table->string('kind', 12);
            $table->string('name');
            $table->string('path');
            $table->unsignedInteger('size');
            $table->string('mime', 128)->nullable();
            $table->char('sha256', 64);
            $table->foreignUuid('uploaded_by')->constrained('users');
            $table->timestamps();
            TenantSchema::key($table);
            TenantSchema::foreign($table, 'task_id', 'log_tasks');
            TenantSchema::foreign($table, 'exception_id', 'log_exceptions');
            $table->index(['organization_id', 'task_id']);
            $table->index(['organization_id', 'exception_id']);
        });

        Schema::create('log_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('user_id')->constrained('users');
            // task, entry, schedule, exception.
            $table->string('category', 12);
            $table->string('event', 48);
            $table->string('title', 160);
            $table->string('body', 300)->nullable();
            $table->string('subject_type', 16);
            $table->uuid('subject_id');
            // Path inside Logbook: entries/…, tasks/…
            $table->string('link', 255);
            // One notification per recipient and key: repeats are ignored.
            $table->string('dedupe_key', 160);
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('emailed_at')->nullable();
            $table->timestampTz('created_at');
            TenantSchema::key($table);
            $table->unique(['organization_id', 'user_id', 'dedupe_key']);
            $table->index(['organization_id', 'user_id', 'read_at', 'created_at']);
        });

        Schema::create('log_notification_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('user_id')->constrained('users');
            // Email on or off by category; missing: on.
            $table->json('email');
            $table->timestamps();
            TenantSchema::key($table);
            $table->unique(['organization_id', 'user_id']);
        });

        Schema::create('log_reminders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            // task, occurrence, exception.
            $table->string('subject_type', 12);
            $table->uuid('subject_id');
            // start, before, due, after, at (a chosen moment).
            $table->string('kind', 8);
            // Minutes from the due time (before: negative, after: positive).
            $table->integer('offset_minutes')->default(0);
            $table->timestampTz('remind_at');
            $table->timestampTz('sent_at')->nullable();
            // sent, or skipped: why (finished, no access…).
            $table->string('outcome', 48)->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestamps();
            TenantSchema::key($table);
            $table->unique(['subject_type', 'subject_id', 'kind', 'offset_minutes', 'remind_at']);
            $table->index(['organization_id', 'subject_type', 'subject_id']);
            $table->index(['sent_at', 'cancelled_at', 'remind_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE log_tasks ADD CONSTRAINT log_tasks_status_check
                    CHECK (status IN ('pending', 'in_progress', 'awaiting_verification', 'returned', 'completed', 'cancelled'));
                ALTER TABLE log_tasks ADD CONSTRAINT log_tasks_priority_check CHECK (priority IN ('low', 'normal', 'high', 'urgent'));
                ALTER TABLE log_task_series ADD CONSTRAINT log_task_series_frequency_check CHECK (frequency IN ('daily', 'weekly', 'monthly'));
                ALTER TABLE log_schedules ADD CONSTRAINT log_schedules_frequency_check CHECK (frequency IN ('daily', 'weekly', 'monthly'));
                ALTER TABLE log_schedules ADD CONSTRAINT log_schedules_completion_check CHECK (completion IN ('submitted', 'reviewed'));
                ALTER TABLE log_schedule_occurrences ADD CONSTRAINT log_schedule_occurrences_status_check
                    CHECK (status IN ('open', 'completed', 'late', 'missed', 'excused'));
                ALTER TABLE log_exceptions ADD CONSTRAINT log_exceptions_status_check CHECK (status IN ('open', 'in_progress', 'resolved', 'closed'));
                ALTER TABLE log_exceptions ADD CONSTRAINT log_exceptions_severity_check CHECK (severity IN ('low', 'medium', 'high', 'critical'));
                ALTER TABLE log_exceptions ADD CONSTRAINT log_exceptions_source_check CHECK (source IN ('reading', 'check', 'manual'));
                ALTER TABLE log_work_files ADD CONSTRAINT log_work_files_owner_check CHECK ((task_id IS NULL) <> (exception_id IS NULL));
                ALTER TABLE log_reminders ADD CONSTRAINT log_reminders_kind_check CHECK (kind IN ('start', 'before', 'due', 'after', 'at'));

                CREATE INDEX log_reminders_pending ON log_reminders (remind_at) WHERE sent_at IS NULL AND cancelled_at IS NULL;
                SQL);
        }
    }

    public function down(): void
    {
        foreach ([
            'log_reminders', 'log_notification_settings', 'log_notifications', 'log_work_files',
            'log_schedule_occurrences', 'log_schedules', 'log_tasks', 'log_task_series',
            'log_exceptions', 'log_resources',
        ] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('log_locations', fn (Blueprint $table) => $table->dropForeign('log_locations_parent_id_tenant_fk'));
        Schema::dropIfExists('log_locations');
    }
};
