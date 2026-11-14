<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Events from Projects (mel.project.updated), applied once each
 * (packages/shared-module-kit/schema/postgres.sql).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shared_module_inbox', function (Blueprint $table) {
            $table->uuid('event_id')->primary();
            $table->string('source_app', 32);
            $table->uuid('organization_id');
            $table->string('source_transaction_id', 128);
            $table->string('event_type', 100);
            $table->smallInteger('schema_version');
            $table->char('content_hash', 64);
            $table->json('payload');
            $table->timestampTz('occurred_at');
            $table->timestampTz('received_at')->useCurrent();
            $table->timestampTz('processed_at')->nullable();

            $table->unique(['source_app', 'organization_id', 'source_transaction_id'], 'shared_module_inbox_source_transaction_unique');
            $table->index(['organization_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_module_inbox');
    }
};
