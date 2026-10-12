<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Business events for shared modules (Finance, HR, Payroll), written in
        // the same transaction as the business change and delivered by a job.
        Schema::create('platform_outbox', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            // Module code: the event token's audience (finance, hr, payroll).
            $table->string('audience', 32);
            $table->string('event_type', 100);
            $table->unsignedSmallInteger('schema_version');
            // The product's id for the business transaction: stable across retries.
            $table->string('source_transaction_id', 128);
            $table->json('payload');
            $table->timestamp('occurred_at');
            // pending → delivered | duplicate | skipped | failed
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'audience', 'source_transaction_id']);
            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_outbox');
    }
};
