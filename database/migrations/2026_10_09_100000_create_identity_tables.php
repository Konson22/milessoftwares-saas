<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Login, logout, MFA and recovery events. Kept separate from audit_logs
        // because volume is high and retention is shorter.
        Schema::create('authentication_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 48);
            $table->string('guard', 32)->default('web');
            // Hash of the attempted identifier, so failed attempts against unknown
            // accounts can be correlated without storing raw emails.
            $table->string('identifier_hash', 64)->nullable();
            $table->string('host')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->jsonb('context')->nullable();
            $table->timestampTz('occurred_at')->useCurrent();

            $table->index(['user_id', 'occurred_at']);
            $table->index(['event', 'occurred_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('authentication_events');
    }
};
