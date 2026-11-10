<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * More inside a task: subtasks (tasks with a parent, one level deep),
 * collaborators besides the lead assignee, a checklist, and attachments
 * (files on the private disk, served only through an authorized route).
 * Purely additive: existing tasks stay top-level, with none of these.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignUuid('parent_id')->nullable()->after('project_id')->constrained('tasks')->restrictOnDelete();
        });

        Schema::create('task_collaborators', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('task_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('person_id')->constrained('people')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['task_id', 'person_id']);
        });

        Schema::create('task_checklist_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('task_id')->constrained()->restrictOnDelete();
            $table->string('body', 255);
            $table->unsignedInteger('position')->default(0);
            $table->timestamp('done_at')->nullable();
            $table->foreignUuid('done_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['task_id', 'position']);
        });

        Schema::create('task_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('task_id')->constrained()->restrictOnDelete();
            $table->string('original_name');
            $table->string('path');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->foreignUuid('uploaded_by')->constrained('users');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_attachments');
        Schema::dropIfExists('task_checklist_items');
        Schema::dropIfExists('task_collaborators');

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
