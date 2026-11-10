<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A list's own named statuses ("Drilling", "Quality check"), each inside one
 * of the four fixed categories. tasks.status stays the category, so progress,
 * health, reports and the done rules don't depend on what a list calls things.
 * Purely additive: lists without any behave as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('list_statuses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('list_id')->constrained('task_lists')->restrictOnDelete();
            $table->string('name', 64);
            // todo | in_progress | review | done (Task::STATUSES).
            $table->string('category', 16);
            $table->unsignedInteger('position')->default(0);
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();
            $table->unique(['list_id', 'name']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignUuid('list_status_id')->nullable()->after('list_id')->constrained('list_statuses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('list_status_id');
        });
        Schema::dropIfExists('list_statuses');
    }
};
