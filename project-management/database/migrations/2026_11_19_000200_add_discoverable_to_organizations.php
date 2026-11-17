<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether other organizations on Tatook Projects can find this one by name to
 * invite it (its name, workspace address and country; nothing else). On by
 * default, turned off in Settings. A published marketplace profile is a
 * separate, richer choice. Projects' own column: the platform mirror
 * (App\Actions\Organizations::mirror) never writes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('discoverable')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('discoverable');
        });
    }
};
