<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per platform webhook event_id: duplicates are dropped.
        Schema::create('platform_webhook_events', function (Blueprint $table) {
            $table->uuid('event_id')->primary();
            $table->string('event_type', 100);
            $table->uuid('organization_id')->nullable()->index();
            $table->timestamp('received_at');
        });

        // One row per provisioning Idempotency-Key: never provision twice.
        Schema::create('platform_provisioned_tenants', function (Blueprint $table) {
            $table->string('idempotency_key', 100)->primary();
            $table->uuid('organization_id')->index();
            $table->string('slug', 63);
            $table->timestamp('provisioned_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_provisioned_tenants');
        Schema::dropIfExists('platform_webhook_events');
    }
};
