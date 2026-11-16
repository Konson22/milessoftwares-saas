<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * ADR-0019 §20.4: a unit's published results go to its linked project's
 * partner reports only when an M&E manager says so, per unit. Off for every
 * existing link: nothing is shared until someone decides to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mel_programme_units', function (Blueprint $table) {
            $table->boolean('shares_with_project_reports')->default(false)->after('linked_project_id');
        });
    }

    public function down(): void
    {
        Schema::table('mel_programme_units', function (Blueprint $table) {
            $table->dropColumn('shares_with_project_reports');
        });
    }
};
