<?php

use App\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * ADR-0030 phase 3: optional operational shifts (Morning 06:00–14:00,
 * Night 22:00–06:00). Not rostering, attendance or payroll: people may be
 * linked to a shift, but that only suggests; it never limits who writes.
 * An entry may name the shift it was recorded in; each revision keeps
 * the shift it was submitted with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('log_shifts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('name', 60);
            // Local wall times "HH:MM"; an end before the start runs past midnight.
            $table->string('starts_time', 5);
            $table->string('ends_time', 5);
            // Null: every logbook of the workspace.
            $table->uuid('logbook_id')->nullable();
            $table->boolean('active')->default(true);
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();
            TenantSchema::key($table);
            TenantSchema::foreign($table, 'logbook_id', 'logbooks');
            $table->index(['organization_id', 'active']);
        });

        Schema::create('log_shift_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('shift_id');
            $table->foreignUuid('user_id')->constrained('users');
            $table->timestamps();
            TenantSchema::key($table);
            TenantSchema::foreign($table, 'shift_id', 'log_shifts');
            $table->unique(['shift_id', 'user_id']);
        });

        foreach (['log_entries', 'log_entry_revisions'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->uuid('shift_id')->nullable();
                TenantSchema::foreign($table, 'shift_id', 'log_shifts');
            });
        }
        Schema::table('log_entries', fn (Blueprint $table) => $table->index(['organization_id', 'shift_id']));
    }

    public function down(): void
    {
        Schema::table('log_entries', fn (Blueprint $table) => $table->dropIndex(['organization_id', 'shift_id']));
        foreach (['log_entry_revisions', 'log_entries'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropForeign($table->getTable().'_shift_id_tenant_fk');
                $table->dropColumn('shift_id');
            });
        }
        Schema::dropIfExists('log_shift_members');
        Schema::dropIfExists('log_shifts');
    }
};
