<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The workspace's address belongs to the platform, which can rename it:
     * a copy kept here went stale. The current one comes with every request
     * (the platform tenant), so the copy goes.
     */
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('slug');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('slug', 63)->nullable();
        });
    }
};
