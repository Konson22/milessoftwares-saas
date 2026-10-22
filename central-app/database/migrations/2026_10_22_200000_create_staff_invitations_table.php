<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Invitations to join the platform staff. The emailed link (its token
     * stored only as a hash) is how someone new gets a password and sets up
     * the two-factor authentication admin sign-in requires.
     */
    public function up(): void
    {
        Schema::create('staff_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('email');
            $table->string('name')->nullable();
            $table->string('role', 64)->nullable();
            $table->string('token_hash', 64)->unique();
            $table->foreignUuid('invited_by_staff_id')->nullable()->constrained('platform_staff')->nullOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_invitations');
    }
};
