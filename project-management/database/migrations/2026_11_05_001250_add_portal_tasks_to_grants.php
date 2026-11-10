<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task lists are a grant's own to share: off for every grant, including those
 * already sharing progress, until its organization turns them on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grants', function (Blueprint $table) {
            $table->boolean('portal_tasks')->default(false)->after('portal_progress');
        });
    }

    public function down(): void
    {
        Schema::table('grants', function (Blueprint $table) {
            $table->dropColumn('portal_tasks');
        });
    }
};
