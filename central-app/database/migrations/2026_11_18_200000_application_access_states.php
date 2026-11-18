<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A member's access to one application has a state an administrator sets:
 * active (may sign in), disabled (not given yet, or taken away) or
 * suspended (stopped for now). Revoked stays for members removed from the
 * workspace. Roles still decide what an active member may do.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE application_access_grants DROP CONSTRAINT application_access_grants_status_check');
        DB::statement("ALTER TABLE application_access_grants ADD CONSTRAINT application_access_grants_status_check CHECK (status IN ('active', 'disabled', 'suspended', 'revoked'))");
    }

    public function down(): void
    {
        // Disabled and suspended access was taken away: it stays taken away.
        DB::table('application_access_grants')->whereIn('status', ['disabled', 'suspended'])->update(['status' => 'revoked', 'revoked_at' => now()]);
        DB::statement('ALTER TABLE application_access_grants DROP CONSTRAINT application_access_grants_status_check');
        DB::statement("ALTER TABLE application_access_grants ADD CONSTRAINT application_access_grants_status_check CHECK (status IN ('active', 'revoked'))");
    }
};
