<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The workspace's people: members who have opened the app (user_id
        // set) and anyone added by hand (contractors), with what an hour of
        // their time costs.
        Schema::create('people', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('user_id')->nullable()->constrained('users');
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('title')->nullable();
            $table->unsignedBigInteger('hourly_cost_minor')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'user_id']);
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('code', 10);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('client')->nullable();
            $table->foreignUuid('manager_id')->nullable()->constrained('people');
            $table->string('status', 16);
            $table->date('start_on')->nullable();
            $table->date('due_on')->nullable();
            $table->char('currency', 3);
            $table->unsignedBigInteger('budget_minor')->default(0);
            // Last task number given out: tasks are CODE-1, CODE-2...
            $table->unsignedInteger('task_sequence')->default(0);
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('project_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('project_id')->constrained();
            $table->foreignUuid('person_id')->constrained('people');
            $table->string('role', 64)->nullable();
            $table->unsignedTinyInteger('allocation_percent')->default(100);
            $table->timestamps();
            $table->unique(['project_id', 'person_id']);
        });

        Schema::create('milestones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('project_id')->constrained();
            $table->string('name');
            $table->date('due_on');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('project_id')->constrained();
            $table->string('number', 24);
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 16);
            $table->string('priority', 8);
            $table->foreignUuid('assignee_id')->nullable()->constrained('people');
            $table->foreignUuid('milestone_id')->nullable()->constrained();
            $table->date('start_on')->nullable();
            $table->date('due_on')->nullable();
            $table->decimal('estimate_hours', 8, 2)->nullable();
            // Order within its column on the board.
            $table->unsignedInteger('position')->default(0);
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'number']);
            $table->index(['project_id', 'status', 'position']);
            $table->index(['assignee_id', 'status']);
        });

        // A task waits for the tasks it depends on.
        Schema::create('task_dependencies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('task_id')->constrained();
            $table->foreignUuid('depends_on_id')->constrained('tasks');
            $table->timestamps();
            $table->unique(['task_id', 'depends_on_id']);
        });

        Schema::create('task_comments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('task_id')->constrained();
            $table->foreignUuid('user_id')->constrained();
            $table->text('body');
            $table->timestamps();
        });

        // Hours worked, costed at the person's rate when logged.
        Schema::create('time_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('project_id')->constrained();
            $table->foreignUuid('task_id')->nullable()->constrained();
            $table->foreignUuid('person_id')->constrained('people');
            $table->date('worked_on');
            $table->unsignedInteger('minutes');
            $table->string('note')->nullable();
            $table->unsignedBigInteger('cost_minor');
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();
            $table->index(['project_id', 'worked_on']);
            $table->index(['person_id', 'worked_on']);
        });

        Schema::create('budget_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('project_id')->constrained();
            $table->string('category', 16);
            $table->string('description')->nullable();
            $table->unsignedBigInteger('amount_minor');
            $table->timestamps();
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('project_id')->constrained();
            $table->string('category', 16);
            $table->string('description');
            $table->string('supplier')->nullable();
            $table->unsignedBigInteger('amount_minor');
            $table->date('spent_on');
            $table->foreignUuid('recorded_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('risks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('project_id')->constrained();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('probability');
            $table->unsignedTinyInteger('impact');
            $table->string('status', 16);
            $table->foreignUuid('owner_id')->nullable()->constrained('people');
            $table->text('mitigation')->nullable();
            $table->date('review_on')->nullable();
            $table->timestamps();
        });

        // What happened on a project, newest first on its overview.
        Schema::create('activities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('project_id')->constrained();
            $table->foreignUuid('user_id')->constrained();
            $table->string('description');
            $table->timestamp('created_at');
            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['activities', 'risks', 'expenses', 'budget_lines', 'time_entries', 'task_comments', 'task_dependencies', 'tasks', 'milestones', 'project_members', 'projects', 'people'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
