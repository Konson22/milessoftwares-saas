<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shared project tasks (Phase 5): a task of the owner's project can be shared
 * with the organizations taking part in it, or given to one of them. The task
 * stays the owner's record. Comments and files say which organization they
 * came from. Additive: every existing task stays internal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            // Seen by every participation that may view the project.
            $table->boolean('shared')->default(false);
            // Given to one participating organization: it sees and works on it.
            $table->foreignUuid('participant_id')->nullable()->constrained('project_participants')->restrictOnDelete();
            $table->index(['project_id', 'participant_id']);
        });
        Schema::table('task_comments', function (Blueprint $table) {
            $table->foreignUuid('participant_id')->nullable()->constrained('project_participants')->restrictOnDelete();
        });
        Schema::table('task_attachments', function (Blueprint $table) {
            $table->foreignUuid('participant_id')->nullable()->constrained('project_participants')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('tasks')->where(fn ($q) => $q->where('shared', true)->orWhereNotNull('participant_id'))->exists()
            || DB::table('task_comments')->whereNotNull('participant_id')->exists()) {
            throw new RuntimeException('Refusing to roll back: tasks are shared with participating organizations, or they commented.');
        }

        Schema::table('task_attachments', fn (Blueprint $table) => $table->dropConstrainedForeignId('participant_id'));
        Schema::table('task_comments', fn (Blueprint $table) => $table->dropConstrainedForeignId('participant_id'));
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'participant_id']);
            $table->dropConstrainedForeignId('participant_id');
            $table->dropColumn('shared');
        });
    }
};
