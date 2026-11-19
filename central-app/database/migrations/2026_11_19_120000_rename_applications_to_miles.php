<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The brand goes back to Miles Softwares (2026-11-19): the platform
 * application is "Miles Softwares platform" and products are "Miles ..."
 * (catalogs platform 1.0.2, logbook 1.0.1, mel 2.0.1, procurement 1.0.1).
 * The catalog importer only names an application when it first creates
 * it, so the existing rows are renamed here.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('applications')
            ->where('code', 'platform')
            ->update(['name' => 'Miles Softwares platform', 'updated_at' => now()]);

        foreach (DB::table('applications')->where('name', 'like', 'Tatook %')->get(['id', 'name']) as $application) {
            DB::table('applications')->where('id', $application->id)->update([
                'name' => 'Miles '.substr($application->name, strlen('Tatook ')),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('applications')
            ->where('code', 'platform')
            ->update(['name' => 'Tatook platform', 'updated_at' => now()]);

        foreach (DB::table('applications')->whereIn('code', ['logbook', 'mel', 'procurement'])->where('name', 'like', 'Miles %')->get(['id', 'name']) as $application) {
            DB::table('applications')->where('id', $application->id)->update([
                'name' => 'Tatook '.substr($application->name, strlen('Miles ')),
                'updated_at' => now(),
            ]);
        }
    }
};
