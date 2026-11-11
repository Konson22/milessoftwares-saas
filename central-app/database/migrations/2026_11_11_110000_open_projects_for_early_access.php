<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Project Management goes on early access: the catalog lists a beta
 * application as early access. The catalog importer only sets an
 * application's status when it first creates it, so the existing row is
 * moved here. No trials yet: early access is by request (a demo), not
 * self-service, so the card offers a demo rather than a trial.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('applications')
            ->where('code', 'projects')
            ->update(['status' => 'beta', 'trial_enabled' => false, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('applications')
            ->where('code', 'projects')
            ->update(['status' => 'retired', 'updated_at' => now()]);
    }
};
