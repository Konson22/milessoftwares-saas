<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Project-level access (stabilization milestone): a project is open to the
 * organization, as every project was until now, or to its team only.
 * Existing projects keep "organization", so nobody loses access on deploy.
 * Reversible: dropping the column restores organization-wide access.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('visibility', 16)->default('organization');
            $table->index(['organization_id', 'visibility']);
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'visibility']);
            $table->dropColumn('visibility');
        });
    }
};
