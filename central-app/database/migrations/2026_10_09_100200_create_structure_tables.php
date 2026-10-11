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
     * Every tenant table exposes UNIQUE (organization_id, id) so children can
     * reference it with a composite foreign key that pins both rows to the same
     * organization.
     */
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 32);
            $table->text('address')->nullable();
            $table->string('city', 128)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('status', 16)->default('active');
            // FK added once organization_memberships exists.
            $table->uuid('manager_membership_id')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'code']);
        });

        DB::statement("ALTER TABLE branches ADD CONSTRAINT branches_status_check CHECK (status IN ('active', 'inactive', 'archived'))");

        // branch_id NULL means the department serves the whole organization.
        Schema::create('departments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('branch_id')->nullable();
            $table->uuid('parent_department_id')->nullable();
            $table->string('name');
            $table->string('code', 32);
            $table->string('status', 16)->default('active');
            $table->timestampsTz();

            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'code']);

            $table->foreign(['organization_id', 'branch_id'])->references(['organization_id', 'id'])->on('branches');
            $table->foreign(['organization_id', 'parent_department_id'])->references(['organization_id', 'id'])->on('departments');
        });

        DB::statement("ALTER TABLE departments ADD CONSTRAINT departments_status_check CHECK (status IN ('active', 'inactive', 'archived'))");
        DB::statement('ALTER TABLE departments ADD CONSTRAINT departments_not_own_parent_check CHECK (parent_department_id IS NULL OR parent_department_id <> id)');

        Schema::create('positions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('department_id')->nullable();
            $table->string('title');
            $table->string('code', 32);
            $table->string('status', 16)->default('active');
            $table->timestampsTz();

            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'code']);

            $table->foreign(['organization_id', 'department_id'])->references(['organization_id', 'id'])->on('departments');
        });

        DB::statement("ALTER TABLE positions ADD CONSTRAINT positions_status_check CHECK (status IN ('active', 'inactive', 'archived'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('positions');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('branches');
    }
};
