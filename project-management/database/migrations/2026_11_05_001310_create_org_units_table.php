<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How an organization is arranged: departments, teams, branches and country
     * offices, one inside another. The foreign keys include organization_id, so
     * the database itself refuses a parent or a member from another organization.
     */
    public function up(): void
    {
        Schema::create('org_units', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('parent_id')->nullable();
            $table->string('kind', 24);
            $table->string('name', 120);
            $table->string('code', 24)->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'parent_id']);
            $table->foreign(['organization_id', 'parent_id'])->references(['organization_id', 'id'])->on('org_units');
        });

        Schema::table('organization_memberships', function (Blueprint $table) {
            $table->uuid('org_unit_id')->nullable()->after('user_id');
            $table->index(['organization_id', 'org_unit_id']);
            $table->foreign(['organization_id', 'org_unit_id'])->references(['organization_id', 'id'])->on('org_units');
        });
    }

    public function down(): void
    {
        Schema::table('organization_memberships', function (Blueprint $table) {
            $table->dropForeign(['organization_id', 'org_unit_id']);
            $table->dropIndex(['organization_id', 'org_unit_id']);
            $table->dropColumn('org_unit_id');
        });

        Schema::dropIfExists('org_units');
    }
};
