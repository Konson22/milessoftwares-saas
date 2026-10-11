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
        // A member may use an application at all. Roles narrow what they can do.
        Schema::create('application_access_grants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('membership_id');
            $table->foreignUuid('application_id')->constrained()->restrictOnDelete();
            $table->string('status', 16)->default('active');
            $table->uuid('granted_by_membership_id')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->unique(['membership_id', 'application_id']);
            $table->foreign(['organization_id', 'membership_id'])->references(['organization_id', 'id'])->on('organization_memberships')->cascadeOnDelete();
            $table->foreign(['organization_id', 'granted_by_membership_id'])->references(['organization_id', 'id'])->on('organization_memberships');
        });

        DB::statement("ALTER TABLE application_access_grants ADD CONSTRAINT application_access_grants_status_check CHECK (status IN ('active', 'revoked'))");

        // One assignment carries exactly one scope (folds the spec's
        // role_assignment_scopes into this table). A user with the same role in
        // two branches has two rows, so scopes can never be merged.
        Schema::create('role_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('membership_id');
            $table->uuid('application_id');
            $table->uuid('application_role_id');
            $table->string('scope_type', 16);
            $table->uuid('branch_id')->nullable();
            $table->uuid('department_id')->nullable();
            $table->string('resource_type', 64)->nullable();
            $table->string('resource_id', 128)->nullable();
            $table->uuid('granted_by_membership_id')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->uuid('revoked_by_membership_id')->nullable();
            $table->timestampsTz();

            $table->index(['organization_id', 'membership_id']);
            $table->index(['organization_id', 'application_id']);

            $table->foreign(['organization_id', 'membership_id'])->references(['organization_id', 'id'])->on('organization_memberships')->cascadeOnDelete();
            $table->foreign(['application_id', 'application_role_id'])->references(['application_id', 'id'])->on('application_roles');
            $table->foreign(['organization_id', 'branch_id'])->references(['organization_id', 'id'])->on('branches');
            $table->foreign(['organization_id', 'department_id'])->references(['organization_id', 'id'])->on('departments');
            $table->foreign(['organization_id', 'granted_by_membership_id'])->references(['organization_id', 'id'])->on('organization_memberships');
            $table->foreign(['organization_id', 'revoked_by_membership_id'])->references(['organization_id', 'id'])->on('organization_memberships');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE role_assignments ADD CONSTRAINT role_assignments_scope_check CHECK (
                (scope_type = 'organization' AND branch_id IS NULL AND department_id IS NULL AND resource_type IS NULL AND resource_id IS NULL)
             OR (scope_type = 'branch'       AND branch_id IS NOT NULL AND department_id IS NULL AND resource_type IS NULL AND resource_id IS NULL)
             OR (scope_type = 'department'   AND branch_id IS NULL AND department_id IS NOT NULL AND resource_type IS NULL AND resource_id IS NULL)
             OR (scope_type = 'resource'     AND branch_id IS NULL AND department_id IS NULL AND resource_type IS NOT NULL AND resource_id IS NOT NULL)
            )
        SQL);

        // No duplicate live assignment of the same role at the same scope.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX role_assignments_active_unique ON role_assignments (
                membership_id, application_role_id, scope_type,
                COALESCE(branch_id, '00000000-0000-0000-0000-000000000000'::uuid),
                COALESCE(department_id, '00000000-0000-0000-0000-000000000000'::uuid),
                COALESCE(resource_type, ''), COALESCE(resource_id, '')
            ) WHERE revoked_at IS NULL
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('role_assignments');
        Schema::dropIfExists('application_access_grants');
    }
};
