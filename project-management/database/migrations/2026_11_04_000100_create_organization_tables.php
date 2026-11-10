<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Organizations (NGOs, donors, partners), who belongs to them, and
     * invitations to join. Every tenant-owned row carries organization_id.
     */
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Its address: /o/{slug}/...
            $table->string('slug', 63)->unique();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->char('country_code', 2)->nullable();
            // Defaults: new projects take this currency; dates use this zone.
            $table->char('currency', 3)->default('USD');
            $table->string('timezone', 64)->default('UTC');
            // active | suspended
            $table->string('status', 16)->default('active');
            // What it can act as in projects (donor, implementing_partner...):
            // capabilities, never one exclusive type.
            $table->json('capabilities')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamps();
        });

        Schema::create('organization_memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            // active | suspended
            $table->string('status', 16)->default('active');
            // The owner holds every permission in the organization.
            $table->boolean('is_owner')->default(false);
            $table->foreignUuid('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            // Role keys granted on acceptance.
            $table->json('roles');
            // sha256 of the token in the link; the token itself is never stored.
            $table->char('token_hash', 64)->unique();
            $table->foreignUuid('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignUuid('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
        Schema::dropIfExists('organization_memberships');
        Schema::dropIfExists('organizations');
    }
};
