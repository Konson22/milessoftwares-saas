<?php

use App\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * ADR-0032 phase 1: who an asset is assigned to (a vehicle's drivers).
 * Managers assign; an assignment runs from a day, optionally to a day, and
 * is ended rather than deleted, so who could drive a vehicle on a given
 * day stays on record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('log_resource_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('log_resource_id');
            $table->foreignUuid('user_id')->constrained('users');
            // Days in the workspace time zone, both inclusive; no end: until ended.
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestampTz('ended_at')->nullable();
            $table->foreignUuid('ended_by')->nullable()->constrained('users');
            $table->timestampsTz();
            TenantSchema::key($table);
            TenantSchema::foreign($table, 'log_resource_id', 'log_resources');
            $table->index(['organization_id', 'user_id', 'starts_on']);
            $table->index(['organization_id', 'log_resource_id', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('log_resource_assignments');
    }
};
