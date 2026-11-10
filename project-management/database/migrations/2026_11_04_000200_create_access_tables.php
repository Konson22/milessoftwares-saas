<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Role-based access, scoped: a role is granted to a membership at a
     * scope. Only organization scope is granted so far; scope_type/scope_id leave
     * room for project and funding-agreement grants (donors, partners).
     * The catalog (config/access.php) is written here by access:sync.
     */
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->string('name');
            $table->unsignedTinyInteger('sensitivity')->default(1);
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // null: a system role, the same in every organization.
            $table->foreignUuid('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key', 64);
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->unique(['organization_id', 'key']);
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->foreignUuid('role_id')->constrained()->cascadeOnDelete();
            $table->string('permission_key', 100);
            $table->foreign('permission_key')->references('key')->on('permissions')->cascadeOnDelete();
            $table->primary(['role_id', 'permission_key']);
        });

        Schema::create('role_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('membership_id')->constrained('organization_memberships')->cascadeOnDelete();
            $table->foreignUuid('role_id')->constrained()->cascadeOnDelete();
            // organization (Phase 2); project, programme, funding_agreement later.
            $table->string('scope_type', 24)->default('organization');
            $table->uuid('scope_id')->nullable();
            $table->foreignUuid('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['membership_id', 'scope_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_assignments');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
