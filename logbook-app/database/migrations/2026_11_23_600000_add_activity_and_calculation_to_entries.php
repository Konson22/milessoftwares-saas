<?php

use App\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * ADR-0030 phase 5: an entry is the activity session (start and end), may
 * belong to another entry's activity, and keeps the fuel calculation it
 * was submitted with. The meter columns repeat the calculation's readings
 * so that the "meter went backwards" and "ranges overlap" checks are
 * plain queries; they are set only at submission.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('log_entries', function (Blueprint $table) {
            $table->timestampTz('activity_started_at')->nullable();
            $table->timestampTz('activity_ended_at')->nullable();
            $table->uuid('activity_entry_id')->nullable();
            $table->json('calculation')->nullable();
            $table->uuid('meter_resource_id')->nullable();
            $table->string('meter_measure', 12)->nullable();
            $table->decimal('meter_start', 12, 1)->nullable();
            $table->decimal('meter_end', 12, 1)->nullable();
            TenantSchema::foreign($table, 'activity_entry_id', 'log_entries');
            TenantSchema::foreign($table, 'meter_resource_id', 'log_resources');
            $table->index(['organization_id', 'meter_resource_id', 'meter_measure', 'activity_started_at'], 'log_entries_meter_index');
            $table->index(['organization_id', 'activity_entry_id']);
        });

        Schema::table('log_entry_revisions', function (Blueprint $table) {
            $table->timestampTz('activity_started_at')->nullable();
            $table->timestampTz('activity_ended_at')->nullable();
            $table->uuid('activity_entry_id')->nullable();
            $table->json('calculation')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('log_entry_revisions', fn (Blueprint $table) => $table->dropColumn(['activity_started_at', 'activity_ended_at', 'activity_entry_id', 'calculation']));
        Schema::table('log_entries', function (Blueprint $table) {
            $table->dropIndex('log_entries_meter_index');
            $table->dropIndex(['organization_id', 'activity_entry_id']);
            $table->dropForeign('log_entries_activity_entry_id_tenant_fk');
            $table->dropForeign('log_entries_meter_resource_id_tenant_fk');
            $table->dropColumn(['activity_started_at', 'activity_ended_at', 'activity_entry_id', 'calculation', 'meter_resource_id', 'meter_measure', 'meter_start', 'meter_end']);
        });
    }
};
