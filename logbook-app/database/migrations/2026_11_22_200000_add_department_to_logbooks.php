<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A logbook belongs to one department of the organization (Central's id),
 * or to none: then only members whose Logbook role covers the whole
 * workspace reach it. Department-scoped roles reach their department's
 * logbooks only (App\Logbook\Access).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('logbooks', function (Blueprint $table) {
            $table->uuid('department_id')->nullable()->after('icon');
            $table->index(['organization_id', 'department_id']);
        });
    }

    public function down(): void
    {
        Schema::table('logbooks', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'department_id']);
            $table->dropColumn('department_id');
        });
    }
};
