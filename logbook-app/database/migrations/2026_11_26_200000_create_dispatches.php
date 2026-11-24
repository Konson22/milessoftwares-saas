<?php

use App\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * ADR-0032 phase 2: trips a manager plans (dispatches): a vehicle, a
 * driver, a day, a destination and a purpose, on a vehicle movement sheet.
 * The driver starts it, which opens the trip entry; the entry names it.
 * Cancelled, never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('log_dispatches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('logbook_id');
            $table->uuid('log_sheet_id');
            $table->uuid('log_resource_id');
            $table->foreignUuid('user_id')->constrained('users');
            $table->date('planned_on');
            // H:i in the workspace time zone; none: any time that day.
            $table->string('planned_time', 5)->nullable();
            $table->string('destination', 200)->nullable();
            $table->string('purpose', 500)->nullable();
            // planned, started (its entry is a draft), done (recorded), cancelled.
            $table->string('status', 12)->default('planned');
            $table->uuid('log_entry_id')->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users');
            $table->string('cancel_reason', 500)->nullable();
            $table->timestampsTz();
            TenantSchema::key($table);
            TenantSchema::foreign($table, 'logbook_id', 'logbooks');
            TenantSchema::foreign($table, 'log_sheet_id', 'log_sheets');
            TenantSchema::foreign($table, 'log_resource_id', 'log_resources');
            $table->index(['organization_id', 'user_id', 'planned_on']);
            $table->index(['organization_id', 'planned_on', 'status']);
        });

        Schema::table('log_entries', function (Blueprint $table) {
            // One entry per planned trip.
            $table->uuid('dispatch_id')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('log_entries', function (Blueprint $table) {
            $table->dropUnique(['dispatch_id']);
            $table->dropColumn('dispatch_id');
        });
        Schema::dropIfExists('log_dispatches');
    }
};
