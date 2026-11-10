<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Where work is organized: Workspace → Space → (Folder) → List → Task.
 * Projects keep the money and accountability; lists hold their tasks.
 *
 * Today's tasks are moved in: each organization with projects gets a
 * "Main" workspace with a "Projects" space, and each project a folder
 * with a "Tasks" list holding all its tasks. Then tasks.list_id is
 * required.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->unsignedInteger('position')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['organization_id', 'archived_at']);
        });

        Schema::create('spaces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('workspace_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->unsignedInteger('position')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('folders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('space_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->unsignedInteger('position')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        // "lists" would be a model named List, a reserved word in PHP.
        Schema::create('task_lists', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('space_id')->constrained()->restrictOnDelete();
            // Lists may sit straight in a space, or in one of its folders.
            $table->foreignUuid('folder_id')->nullable()->constrained()->restrictOnDelete();
            // The project whose tasks it holds (required for now: tasks are
            // numbered and authorized through their project).
            $table->foreignUuid('project_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->unsignedInteger('position')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['project_id', 'archived_at']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignUuid('list_id')->nullable()->after('project_id')->constrained('task_lists')->restrictOnDelete();
        });

        DB::transaction(function () {
            $now = now();
            $spaces = [];
            $projects = DB::table('projects')->orderBy('created_at')->get(['id', 'organization_id', 'code', 'name']);

            foreach ($projects as $index => $project) {
                if (! isset($spaces[$project->organization_id])) {
                    $workspace = (string) Str::uuid7();
                    DB::table('workspaces')->insert(['id' => $workspace, 'organization_id' => $project->organization_id, 'name' => 'Main', 'created_at' => $now, 'updated_at' => $now]);
                    $spaces[$project->organization_id] = (string) Str::uuid7();
                    DB::table('spaces')->insert(['id' => $spaces[$project->organization_id], 'organization_id' => $project->organization_id, 'workspace_id' => $workspace, 'name' => 'Projects', 'created_at' => $now, 'updated_at' => $now]);
                }
                $space = $spaces[$project->organization_id];

                $folder = (string) Str::uuid7();
                DB::table('folders')->insert(['id' => $folder, 'organization_id' => $project->organization_id, 'space_id' => $space, 'name' => mb_substr("{$project->code} · {$project->name}", 0, 120), 'position' => $index, 'created_at' => $now, 'updated_at' => $now]);
                $list = (string) Str::uuid7();
                DB::table('task_lists')->insert(['id' => $list, 'organization_id' => $project->organization_id, 'space_id' => $space, 'folder_id' => $folder, 'project_id' => $project->id, 'name' => 'Tasks', 'created_at' => $now, 'updated_at' => $now]);

                DB::table('tasks')->where('project_id', $project->id)->update(['list_id' => $list]);
            }

            $homeless = DB::table('tasks')->whereNull('list_id')->count();
            if ($homeless > 0) {
                throw new RuntimeException("{$homeless} tasks were left without a list; nothing was changed.");
            }
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignUuid('list_id')->nullable(false)->change();
            $table->index(['list_id', 'status', 'position']);
        });
    }

    public function down(): void
    {
        // The key first: MySQL lets the composite index serve it.
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeign(['list_id']);
        });
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['list_id', 'status', 'position']);
            $table->dropColumn('list_id');
        });
        Schema::dropIfExists('task_lists');
        Schema::dropIfExists('folders');
        Schema::dropIfExists('spaces');
        Schema::dropIfExists('workspaces');
    }
};
