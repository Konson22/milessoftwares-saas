<?php

use App\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Recurring work and delivery (ADR-0027).
 *
 * - Series and schedules can be paused, resumed and ended. `generate_from`
 *   is the moment from which occurrences are made, so resuming never fills
 *   in the paused days, and the clock can catch up after an outage without
 *   reaching back past it.
 * - Reminder channels per record (push, email; in-app always).
 * - A task keeps when the server received its completion beside the time
 *   the phone says the work was done.
 * - An entry may name the expected occurrence it was written for.
 * - Phones register push tokens; each notification has one delivery row per
 *   channel, with attempts and retry times.
 *
 * Additive: new columns are nullable, new tables are new. down() removes
 * exactly these.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['log_task_series', 'log_schedules'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->timestampTz('generate_from')->nullable();
                $table->timestampTz('paused_at')->nullable();
                $table->timestampTz('ended_at')->nullable();
                $table->json('reminder_channels')->nullable();
            });
        }
        // As before: a series made nothing due before it was created, a schedule nothing due before its last change.
        DB::table('log_task_series')->update(['generate_from' => DB::raw('created_at')]);
        DB::table('log_schedules')->update(['generate_from' => DB::raw('updated_at')]);
        foreach (['log_task_series', 'log_schedules'] as $name) {
            DB::table($name)->where('active', false)->update(['paused_at' => DB::raw('updated_at')]);
        }

        Schema::table('log_tasks', function (Blueprint $table) {
            $table->json('reminder_channels')->nullable();
            $table->timestampTz('completion_received_at')->nullable();
        });

        Schema::table('log_schedule_occurrences', function (Blueprint $table) {
            // on_time or late: when the entry came in, whatever the review later says.
            $table->string('timeliness', 8)->nullable();
        });
        DB::table('log_schedule_occurrences')->where('status', 'completed')->update(['timeliness' => 'on_time']);
        DB::table('log_schedule_occurrences')->where('status', 'late')->update(['timeliness' => 'late']);

        Schema::table('log_entries', function (Blueprint $table) {
            $table->uuid('schedule_occurrence_id')->nullable();
            TenantSchema::foreign($table, 'schedule_occurrence_id', 'log_schedule_occurrences');
        });

        Schema::table('log_notification_settings', function (Blueprint $table) {
            // Push on or off by category; missing: on.
            $table->json('push')->nullable();
        });

        Schema::create('log_push_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('user_id')->constrained('users');
            // An Expo push token. One install has one token: whoever registers it last owns it.
            $table->string('token', 255)->unique();
            $table->string('platform', 12);
            // The phone's own id for this install, so a refreshed token replaces the old one.
            $table->string('install_id', 64);
            $table->string('app_version', 32)->nullable();
            $table->timestampTz('last_seen_at');
            $table->unsignedSmallInteger('failures')->default(0);
            $table->timestampTz('revoked_at')->nullable();
            // signed_out, unregistered (the provider said so), replaced.
            $table->string('revoked_reason', 24)->nullable();
            $table->timestamps();
            TenantSchema::key($table);
            $table->index(['organization_id', 'user_id', 'revoked_at']);
            $table->index('install_id');
        });

        Schema::create('log_notification_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('notification_id');
            // email, push.
            $table->string('channel', 8);
            // pending, sent, failed (gave up), skipped (nothing to send to, or turned off).
            $table->string('status', 8)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->string('last_error', 300)->nullable();
            // Push: the provider's ticket per device, checked later for a receipt.
            $table->json('tickets')->nullable();
            $table->timestampTz('receipts_checked_at')->nullable();
            $table->timestamps();
            TenantSchema::key($table);
            TenantSchema::foreign($table, 'notification_id', 'log_notifications');
            // One row per notification and channel: a repeated event or a retried job adds nothing.
            $table->unique(['notification_id', 'channel']);
            $table->index(['status', 'next_attempt_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE log_notification_deliveries ADD CONSTRAINT log_notification_deliveries_channel_check CHECK (channel IN ('email', 'push'));
                ALTER TABLE log_notification_deliveries ADD CONSTRAINT log_notification_deliveries_status_check CHECK (status IN ('pending', 'sent', 'failed', 'skipped'));
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('log_notification_deliveries');
        Schema::dropIfExists('log_push_devices');
        Schema::table('log_notification_settings', fn (Blueprint $table) => $table->dropColumn('push'));
        Schema::table('log_entries', function (Blueprint $table) {
            $table->dropForeign('log_entries_schedule_occurrence_id_tenant_fk');
            $table->dropColumn('schedule_occurrence_id');
        });
        Schema::table('log_schedule_occurrences', fn (Blueprint $table) => $table->dropColumn('timeliness'));
        Schema::table('log_tasks', fn (Blueprint $table) => $table->dropColumn(['reminder_channels', 'completion_received_at']));
        foreach (['log_task_series', 'log_schedules'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn(['generate_from', 'paused_at', 'ended_at', 'reminder_channels']));
        }
    }
};
