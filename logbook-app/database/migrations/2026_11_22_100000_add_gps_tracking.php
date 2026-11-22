<?php

use App\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Optional GPS (ADR-0028), phone only.
 *
 * - A log sheet version says whether locations are taken: off (the
 *   default, null), the entry's location, or a route. It is part of the
 *   version, so publishing a change never alters past entries.
 * - Tracks: a route recorded by one person on one phone, keyed by the
 *   phone's id, so a retried upload never makes a second one. `source`
 *   names where the positions came from: only `phone` now.
 * - Track points and events (start, pause, resume, end, interruptions)
 *   share the phone's sequence: unique (track, seq), so a resent batch
 *   adds nothing. Points are kept as observed; the summary on the track
 *   is worked out from them.
 * - An entry may carry the location taken for it and the track it was
 *   written on; each revision keeps the location it was submitted with.
 *
 * Additive: new columns are nullable, new tables are new. down() removes
 * exactly these.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('log_sheet_versions', function (Blueprint $table) {
            // {mode: entry|route, required, resource_required, accuracy_m}; null: no GPS.
            $table->json('location')->nullable();
        });

        Schema::create('log_tracks', function (Blueprint $table) {
            // The phone's UUID: the idempotency key of the whole route.
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('user_id')->constrained('users');
            $table->uuid('logbook_id');
            $table->uuid('log_sheet_id');
            $table->uuid('log_resource_id')->nullable();
            $table->uuid('task_id')->nullable();
            // Where the positions come from. Only `phone` is accepted now.
            $table->string('source', 12)->default('phone');
            // recording, paused, ended.
            $table->string('status', 10);
            $table->timestampTz('started_at');
            $table->timestampTz('ended_at')->nullable();
            // Worked out on the server from the points received.
            $table->unsignedInteger('distance_m')->default(0);
            $table->unsignedInteger('moving_seconds')->default(0);
            $table->unsignedInteger('points_count')->default(0);
            $table->unsignedInteger('rejected_points')->default(0);
            $table->unsignedSmallInteger('gaps_count')->default(0);
            $table->unsignedInteger('gap_seconds')->default(0);
            $table->unsignedInteger('last_seq')->default(0);
            $table->timestampTz('last_point_at')->nullable();
            $table->json('bounds')->nullable();
            $table->string('device', 120)->nullable();
            $table->timestamps();
            TenantSchema::key($table);
            TenantSchema::foreign($table, 'logbook_id', 'logbooks');
            TenantSchema::foreign($table, 'log_sheet_id', 'log_sheets');
            TenantSchema::foreign($table, 'log_resource_id', 'log_resources');
            TenantSchema::foreign($table, 'task_id', 'log_tasks');
            $table->index(['organization_id', 'started_at']);
            $table->index(['organization_id', 'user_id', 'started_at']);
            $table->index(['organization_id', 'log_resource_id', 'started_at']);
        });

        Schema::create('log_track_points', function (Blueprint $table) {
            $table->id();
            $table->uuid('organization_id');
            $table->uuid('log_track_id');
            $table->unsignedInteger('seq');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->float('accuracy_m')->nullable();
            $table->float('speed_mps')->nullable();
            $table->float('altitude_m')->nullable();
            $table->timestampTz('captured_at');
            $table->timestampTz('received_at');
            TenantSchema::foreign($table, 'log_track_id', 'log_tracks');
            $table->unique(['log_track_id', 'seq']);
        });

        Schema::create('log_track_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('organization_id');
            $table->uuid('log_track_id');
            $table->unsignedInteger('seq');
            // start, pause, resume, end, interrupted, recovered, gps_off, gps_on, permission_lost.
            $table->string('kind', 20);
            $table->timestampTz('at');
            $table->string('note', 200)->nullable();
            $table->timestampTz('received_at');
            TenantSchema::foreign($table, 'log_track_id', 'log_tracks');
            $table->unique(['log_track_id', 'seq']);
        });

        Schema::table('log_entries', function (Blueprint $table) {
            $table->uuid('log_track_id')->nullable();
            // The location taken for the entry: {lat, lng, accuracy_m, captured_at, received_at, source}.
            $table->json('location')->nullable();
            TenantSchema::foreign($table, 'log_track_id', 'log_tracks');
            $table->index(['organization_id', 'log_track_id']);
        });

        Schema::table('log_entry_revisions', function (Blueprint $table) {
            $table->json('location')->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE log_tracks ADD CONSTRAINT log_tracks_source_check CHECK (source IN ('phone'));
                ALTER TABLE log_tracks ADD CONSTRAINT log_tracks_status_check CHECK (status IN ('recording', 'paused', 'ended'));
                ALTER TABLE log_track_points ADD CONSTRAINT log_track_points_coordinates_check CHECK (latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180);
                ALTER TABLE log_track_events ADD CONSTRAINT log_track_events_kind_check CHECK (kind IN ('start', 'pause', 'resume', 'end', 'interrupted', 'recovered', 'gps_off', 'gps_on', 'permission_lost'));
                SQL);
        }
    }

    public function down(): void
    {
        Schema::table('log_entry_revisions', fn (Blueprint $table) => $table->dropColumn('location'));
        Schema::table('log_entries', function (Blueprint $table) {
            $table->dropForeign('log_entries_log_track_id_tenant_fk');
            $table->dropIndex(['organization_id', 'log_track_id']);
            $table->dropColumn(['log_track_id', 'location']);
        });
        Schema::dropIfExists('log_track_events');
        Schema::dropIfExists('log_track_points');
        Schema::dropIfExists('log_tracks');
        Schema::table('log_sheet_versions', fn (Blueprint $table) => $table->dropColumn('location'));
    }
};
