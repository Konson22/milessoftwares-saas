<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Licence keys for the offline desktop pharmacy, and the PCs they are
     * activated on. A key (MILES-XXXX-XXXX-XXXX, stored only as a hash) is
     * sold or handed out before anyone knows the PC; activating ties it to
     * one device and one pharmacy and answers a signed activation code.
     */
    public function up(): void
    {
        Schema::create('licence_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key_hash', 64)->unique();
            // The last four characters, to find a key a customer reads out.
            $table->string('key_hint', 4);
            $table->string('product', 32)->default('pharmacy');
            $table->string('type', 32)->default('single');
            // How long a licence runs from activation; null: no end.
            $table->unsignedSmallInteger('duration_months')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('customer_phone', 32)->nullable();
            $table->text('note')->nullable();
            $table->foreignUuid('issued_by_staff_id')->nullable()->constrained('platform_staff')->nullOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->string('revoke_reason')->nullable();
            $table->timestampsTz();

            $table->index('key_hint');
        });

        Schema::create('licence_activations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('licence_key_id')->constrained('licence_keys')->cascadeOnDelete();
            $table->string('device_code', 16);
            $table->string('pharmacy_id', 16);
            $table->string('pharmacy_name')->nullable();
            $table->timestampTz('activated_at');
            $table->timestampTz('expires_at')->nullable();
            // Moved to another PC (or revoked): no longer the key's activation.
            $table->timestampTz('released_at')->nullable();
            $table->string('channel', 16); // app, web, staff
            $table->string('ip_address', 45)->nullable();
            $table->string('app_version', 32)->nullable();
            $table->timestampsTz();

            $table->index(['licence_key_id', 'released_at']);
            $table->index('device_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licence_activations');
        Schema::dropIfExists('licence_keys');
    }
};
