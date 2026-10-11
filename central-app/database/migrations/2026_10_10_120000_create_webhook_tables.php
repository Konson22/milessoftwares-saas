<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Outgoing webhooks to products. One endpoint per application; each event
     * becomes one delivery row per endpoint, retried with backoff. The row id
     * is the event id products use for idempotency.
     */
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('application_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('url', 2048);
            // Encrypted with the application key; shown to staff once.
            $table->text('secret');
            $table->string('status', 16)->default('active');
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE webhook_endpoints ADD CONSTRAINT webhook_endpoints_status_check CHECK (status IN ('active', 'disabled'))");

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('webhook_endpoint_id')->constrained()->cascadeOnDelete();
            $table->uuid('organization_id')->nullable();
            $table->string('event_type', 64);
            $table->jsonb('payload');
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('next_attempt_at')->nullable();
            $table->unsignedSmallInteger('last_response_code')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'next_attempt_at']);
            $table->index(['webhook_endpoint_id', 'created_at']);
        });

        DB::statement("ALTER TABLE webhook_deliveries ADD CONSTRAINT webhook_deliveries_status_check CHECK (status IN ('pending', 'delivered', 'failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
    }
};
