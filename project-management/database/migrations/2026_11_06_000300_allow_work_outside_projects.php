<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lists and tasks may belong to no project: an organization's day-to-day
 * work. Tasks with a project keep their per-project numbers; the others are
 * numbered across the organization, and that is unique too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_lists', function (Blueprint $table) {
            $table->uuid('project_id')->nullable()->change();
        });
        Schema::table('tasks', function (Blueprint $table) {
            $table->uuid('project_id')->nullable()->change();
        });

        DB::statement('CREATE UNIQUE INDEX tasks_outside_projects_number_unique ON tasks (organization_id, number) WHERE project_id IS NULL');
    }

    public function down(): void
    {
        if (DB::table('tasks')->whereNull('project_id')->exists() || DB::table('task_lists')->whereNull('project_id')->exists()) {
            throw new RuntimeException('Lists or tasks outside projects exist: move or remove them before rolling this back.');
        }

        DB::statement('DROP INDEX tasks_outside_projects_number_unique');

        Schema::table('tasks', function (Blueprint $table) {
            $table->uuid('project_id')->nullable(false)->change();
        });
        Schema::table('task_lists', function (Blueprint $table) {
            $table->uuid('project_id')->nullable(false)->change();
        });
    }
};
