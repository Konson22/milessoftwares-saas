<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The brand becomes Miles Global Softwares (2026-11-25, catalog platform
 * 1.0.3). The catalog importer only names an application when it first
 * creates it, so the existing platform row is renamed here. Products keep
 * their "Miles ..." names.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('applications')
            ->where('code', 'platform')
            ->update(['name' => 'Miles Global Softwares platform', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('applications')
            ->where('code', 'platform')
            ->update(['name' => 'Miles Softwares platform', 'updated_at' => now()]);
    }
};
