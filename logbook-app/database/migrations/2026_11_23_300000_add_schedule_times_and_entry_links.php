<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * ADR-0030 phase 2: several readings a day on one schedule, windows that
 * run past midnight, and entries that say which reading they are for.
 *
 * - log_schedules.times: [{opens, due}, …] local wall times; the first
 *   stays in opens_time / due_time, so older readers keep working.
 * - log_schedule_occurrences.slot: which of those times (0 for every
 *   existing row), part of the unique key, so a retried run never makes
 *   a reading twice.
 * - log_entries.schedule_link: named (for the occurrence it names),
 *   extra (for none), or null for entries from clients that do not say,
 *   which keep the earlier match by time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('log_schedules', function (Blueprint $table) {
            $table->json('times')->nullable();
        });

        Schema::table('log_schedule_occurrences', function (Blueprint $table) {
            $table->unsignedSmallInteger('slot')->default(0);
        });
        Schema::table('log_schedule_occurrences', function (Blueprint $table) {
            $table->dropUnique(['schedule_id', 'occurs_on']);
            $table->unique(['schedule_id', 'occurs_on', 'slot']);
        });

        Schema::table('log_entries', function (Blueprint $table) {
            $table->string('schedule_link', 8)->nullable();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE log_entries ADD CONSTRAINT log_entries_schedule_link_check CHECK (schedule_link IS NULL OR schedule_link IN ('named', 'extra'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE log_entries DROP CONSTRAINT IF EXISTS log_entries_schedule_link_check');
        }
        Schema::table('log_entries', fn (Blueprint $table) => $table->dropColumn('schedule_link'));
        Schema::table('log_schedule_occurrences', function (Blueprint $table) {
            $table->dropUnique(['schedule_id', 'occurs_on', 'slot']);
        });
        $extra = DB::table('log_schedule_occurrences')->where('slot', '>', 0)->pluck('id');
        DB::table('log_entries')->whereIn('schedule_occurrence_id', $extra)->update(['schedule_occurrence_id' => null]);
        DB::table('log_schedule_occurrences')->whereIn('id', $extra)->delete();
        Schema::table('log_schedule_occurrences', function (Blueprint $table) {
            $table->unique(['schedule_id', 'occurs_on']);
            $table->dropColumn('slot');
        });
        Schema::table('log_schedules', fn (Blueprint $table) => $table->dropColumn('times'));
    }
};
