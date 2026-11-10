<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Notifications about more than tasks: approvals and reminders, which open a
 * page rather than a task. A reminder has a key, so it is given once; and
 * what has been emailed is marked, so the daily digest repeats nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_notifications', function (Blueprint $table) {
            $table->uuid('project_id')->nullable()->change();
            $table->uuid('task_id')->nullable()->change();
            $table->string('url')->nullable();
            $table->string('dedupe_key', 120)->nullable();
            $table->timestamp('emailed_at')->nullable();
            $table->unique(['user_id', 'dedupe_key']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('email_alerts')->default(true);
        });
    }

    public function down(): void
    {
        // Notifications that open a page cannot exist in the old shape.
        DB::table('user_notifications')->whereNull('task_id')->delete();

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('email_alerts');
        });
        Schema::table('user_notifications', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'dedupe_key']);
            $table->dropColumn(['url', 'dedupe_key', 'emailed_at']);
            $table->uuid('project_id')->nullable(false)->change();
            $table->uuid('task_id')->nullable(false)->change();
        });
    }
};
