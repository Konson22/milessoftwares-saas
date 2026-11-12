<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What an organization says it is at sign-up (a donor or funder, or an
 * organization that runs the work), passed to products that care (Projects).
 *
 * And workspaces one organization starts for another: a donor creates its
 * grantee's workspace from Projects. It stays pending, with no owner, until the
 * person it was made for claims it with the emailed link (organization_claims).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('organization_type', 24)->nullable()->after('business_type');
        });
        DB::statement("ALTER TABLE organizations ADD CONSTRAINT organizations_type_check CHECK (organization_type IS NULL OR organization_type IN ('donor', 'organization'))");

        Schema::create('organization_claims', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // The pending workspace waiting for its owner.
            $table->foreignUuid('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('name');
            $table->string('token_hash', 64)->nullable()->unique();
            $table->foreignUuid('sponsor_organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('sponsor_user_id')->nullable()->constrained('users')->nullOnDelete();
            // The application that asked; its trial starts when the workspace is claimed.
            $table->foreignUuid('application_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('pending');
            $table->timestampTz('expires_at');
            $table->timestampTz('claimed_at')->nullable();
            $table->foreignUuid('claimed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['sponsor_organization_id', 'status']);
        });
        DB::statement("ALTER TABLE organization_claims ADD CONSTRAINT organization_claims_status_check CHECK (status IN ('pending', 'claimed', 'cancelled'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_claims');
        DB::statement('ALTER TABLE organizations DROP CONSTRAINT IF EXISTS organizations_type_check');
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('organization_type');
        });
    }
};
