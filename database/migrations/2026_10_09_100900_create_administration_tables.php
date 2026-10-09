<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Internal Miles Global Softwares staff. Platform roles are kept entirely
     * separate from organization and application roles.
     */
    public function up(): void
    {
        Schema::create('platform_staff', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('status', 16)->default('active');
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE platform_staff ADD CONSTRAINT platform_staff_status_check CHECK (status IN ('active', 'suspended', 'removed'))");

        Schema::create('platform_roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key', 64)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestampsTz();
        });

        Schema::create('platform_staff_roles', function (Blueprint $table) {
            $table->foreignUuid('platform_staff_id')->constrained('platform_staff')->cascadeOnDelete();
            $table->foreignUuid('platform_role_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('granted_by_staff_id')->nullable()->constrained('platform_staff')->nullOnDelete();
            $table->timestampTz('granted_at')->useCurrent();

            $table->primary(['platform_staff_id', 'platform_role_id']);
        });

        // Explicit, scoped, temporary and justified access to one customer.
        Schema::create('support_access_grants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('platform_staff_id')->constrained('platform_staff')->restrictOnDelete();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->text('reason');
            $table->string('ticket_reference', 64)->nullable();
            $table->string('scope', 32)->default('read_only');
            $table->foreignUuid('approved_by_staff_id')->nullable()->constrained('platform_staff')->nullOnDelete();
            $table->timestampTz('starts_at');
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->index(['organization_id', 'expires_at']);
        });

        DB::statement("ALTER TABLE support_access_grants ADD CONSTRAINT support_access_grants_scope_check CHECK (scope IN ('read_only', 'configuration'))");
        DB::statement("ALTER TABLE support_access_grants ADD CONSTRAINT support_access_grants_window_check CHECK (expires_at > starts_at AND expires_at <= starts_at + interval '72 hours')");
        DB::statement('ALTER TABLE support_access_grants ADD CONSTRAINT support_access_grants_not_self_approved_check CHECK (approved_by_staff_id IS NULL OR approved_by_staff_id <> platform_staff_id)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('support_access_grants');
        Schema::dropIfExists('platform_staff_roles');
        Schema::dropIfExists('platform_roles');
        Schema::dropIfExists('platform_staff');
    }
};
