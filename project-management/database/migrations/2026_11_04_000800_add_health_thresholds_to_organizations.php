<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How far a project may slip before it shows "at risk" (ProjectMetrics),
 * set per organization in percentage points.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            // Progress may trail the time elapsed by this many points.
            $table->unsignedSmallInteger('health_schedule_slack')->default(20);
            // The share of the budget spent may run this far ahead of progress.
            $table->unsignedSmallInteger('health_budget_slack')->default(20);
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['health_schedule_slack', 'health_budget_slack']);
        });
    }
};
