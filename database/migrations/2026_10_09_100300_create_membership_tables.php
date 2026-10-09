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
        // Global identity (users) is separate from organization membership. An
        // employee record in HR is neither; it may later link to a user.
        Schema::create('organization_memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->uuid('position_id')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestampTz('joined_at')->nullable();
            $table->timestampTz('suspended_at')->nullable();
            $table->timestampTz('removed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'user_id']);
            $table->index('user_id');

            $table->foreign(['organization_id', 'position_id'])->references(['organization_id', 'id'])->on('positions');
        });

        DB::statement("ALTER TABLE organization_memberships ADD CONSTRAINT organization_memberships_status_check CHECK (status IN ('active', 'suspended', 'removed'))");

        Schema::create('membership_branch_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('membership_id');
            $table->uuid('branch_id');
            $table->boolean('is_primary')->default(false);
            $table->timestampsTz();

            $table->unique(['membership_id', 'branch_id']);
            $table->foreign(['organization_id', 'membership_id'])->references(['organization_id', 'id'])->on('organization_memberships')->cascadeOnDelete();
            $table->foreign(['organization_id', 'branch_id'])->references(['organization_id', 'id'])->on('branches');
        });

        DB::statement('CREATE UNIQUE INDEX membership_branch_assignments_one_primary ON membership_branch_assignments (membership_id) WHERE is_primary');

        Schema::create('membership_department_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('membership_id');
            $table->uuid('department_id');
            $table->timestampsTz();

            $table->unique(['membership_id', 'department_id']);
            $table->foreign(['organization_id', 'membership_id'])->references(['organization_id', 'id'])->on('organization_memberships')->cascadeOnDelete();
            $table->foreign(['organization_id', 'department_id'])->references(['organization_id', 'id'])->on('departments');
        });

        // Deferred FKs that point back at memberships.
        Schema::table('organizations', function (Blueprint $table) {
            $table->foreign(['id', 'owner_membership_id'])->references(['organization_id', 'id'])->on('organization_memberships');
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->foreign(['organization_id', 'manager_membership_id'])->references(['organization_id', 'id'])->on('organization_memberships');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('branches', fn (Blueprint $table) => $table->dropForeign(['organization_id', 'manager_membership_id']));
        Schema::table('organizations', fn (Blueprint $table) => $table->dropForeign(['id', 'owner_membership_id']));

        Schema::dropIfExists('membership_department_assignments');
        Schema::dropIfExists('membership_branch_assignments');
        Schema::dropIfExists('organization_memberships');
    }
};
