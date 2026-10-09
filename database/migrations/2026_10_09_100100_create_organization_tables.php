<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('legal_name');
            $table->string('display_name');
            $table->string('business_type', 48)->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->text('address')->nullable();
            $table->char('country_code', 2);
            $table->char('default_currency', 3);
            $table->string('timezone', 64);
            $table->string('status', 16)->default('pending');
            // FK added once organization_memberships exists.
            $table->uuid('owner_membership_id')->nullable();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('suspended_at')->nullable();
            $table->timestampTz('archived_at')->nullable();
            // Set when an archived organization enters the deletion window.
            $table->timestampTz('purge_after')->nullable();
            $table->timestampsTz();

            $table->index('status');
        });

        DB::statement("ALTER TABLE organizations ADD CONSTRAINT organizations_status_check CHECK (status IN ('pending', 'active', 'suspended', 'archived'))");
        DB::statement('ALTER TABLE organizations ADD CONSTRAINT organizations_country_code_check CHECK (country_code ~ \'^[A-Z]{2}$\')');
        DB::statement('ALTER TABLE organizations ADD CONSTRAINT organizations_currency_check CHECK (default_currency ~ \'^[A-Z]{3}$\')');

        // Slug history. Exactly one primary slug per organization; retired slugs
        // are kept forever so they can never be re-registered by someone else.
        Schema::create('organization_slugs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 63);
            $table->boolean('is_primary')->default(false);
            $table->timestampTz('retired_at')->nullable();
            $table->timestampTz('redirect_until')->nullable();
            $table->timestampsTz();

            $table->unique('slug');
        });

        DB::statement('ALTER TABLE organization_slugs ADD CONSTRAINT organization_slugs_format_check CHECK (slug ~ \'^[a-z][a-z0-9-]*[a-z0-9]$\' AND slug NOT LIKE \'%--%\')');
        DB::statement('ALTER TABLE organization_slugs ADD CONSTRAINT organization_slugs_primary_not_retired_check CHECK (NOT (is_primary AND retired_at IS NOT NULL))');
        DB::statement('CREATE UNIQUE INDEX organization_slugs_one_primary ON organization_slugs (organization_id) WHERE is_primary');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_slugs');
        Schema::dropIfExists('organizations');
    }
};
