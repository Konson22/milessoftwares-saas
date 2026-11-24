<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * ADR-0032 phase 3: the points a trip stopped at, kept on the trip entry
 * and on each of its revisions like its values: place, arrival and
 * departure, odometer, purpose, note, an optional GPS pin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('log_entries', function (Blueprint $table) {
            $table->json('stops')->nullable();
        });
        Schema::table('log_entry_revisions', function (Blueprint $table) {
            $table->json('stops')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('log_entry_revisions', function (Blueprint $table) {
            $table->dropColumn('stops');
        });
        Schema::table('log_entries', function (Blueprint $table) {
            $table->dropColumn('stops');
        });
    }
};
