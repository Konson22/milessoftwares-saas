<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Project Management and Petrol Station, both on early access (beta), are
 * open for free trials now that their apps run in production: the catalog
 * offers a trial for a beta application that allows one.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('applications')
            ->whereIn('code', ['projects', 'petrol'])
            ->update(['trial_enabled' => true, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('applications')
            ->whereIn('code', ['projects', 'petrol'])
            ->update(['trial_enabled' => false, 'updated_at' => now()]);
    }
};
