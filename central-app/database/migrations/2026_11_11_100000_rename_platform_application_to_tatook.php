<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The platform application takes the Tatook name. The catalog importer
 * only sets an application's name when it first creates it, so the
 * existing row is renamed here.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('applications')
            ->where('code', 'platform')
            ->update(['name' => 'Tatook platform', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('applications')
            ->where('code', 'platform')
            ->update(['name' => 'Miles Softwares platform', 'updated_at' => now()]);
    }
};
