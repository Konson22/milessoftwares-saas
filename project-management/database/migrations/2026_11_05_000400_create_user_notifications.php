<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-app notifications: what happened on a task that someone should know
 * about. Each belongs to one person in one organization.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            // mentioned | commented | assigned | added
            $table->string('kind', 16);
            $table->foreignUuid('project_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('task_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->string('excerpt')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'organization_id', 'read_at']);
            $table->index(['user_id', 'organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
    }
};
