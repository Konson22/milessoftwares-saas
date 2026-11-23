<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * ADR-0030 phase 1: how a log sheet is recorded (scheduled, event-based or
 * on demand) is part of its template version, published with it, so a
 * change never rewrites what earlier entries were written under.
 * Null on every existing version: read as on demand, or scheduled when
 * the sheet already has a schedule (App\Logbook\Recording::mode).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('log_sheet_versions', function (Blueprint $table) {
            $table->json('recording')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('log_sheet_versions', fn (Blueprint $table) => $table->dropColumn('recording'));
    }
};
