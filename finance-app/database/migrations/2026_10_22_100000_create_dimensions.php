<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cost centres and projects: what a line of income or spending was for.
        Schema::create('dimension_values', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            // cost_centre or project.
            $table->string('kind', 16);
            $table->string('code', 16);
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'kind', 'code']);
        });

        // Set when the line is posted, like the rest of the line.
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->foreignUuid('cost_centre_id')->nullable()->constrained('dimension_values');
            $table->foreignUuid('project_id')->nullable()->constrained('dimension_values');
        });

        // Lines on these accounts entered by hand must say which.
        Schema::table('accounts', function (Blueprint $table) {
            $table->boolean('requires_cost_centre')->default(false);
            $table->boolean('requires_project')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['requires_cost_centre', 'requires_project']);
        });
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
            $table->dropConstrainedForeignId('cost_centre_id');
        });
        Schema::dropIfExists('dimension_values');
    }
};
