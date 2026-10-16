<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Accounts an organization's administrator creates with a temporary
     * password. Until the person sets their own password they can do nothing
     * else, and the temporary one stops working after a while. The email was
     * given by the administrator, not proven by the person: such accounts may
     * use that organization without email verification, but cannot accept
     * another organization's invitation until the address is verified.
     *
     * Additive only.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestampTz('password_change_required_at')->nullable()->after('password');
            $table->timestampTz('temporary_password_expires_at')->nullable()->after('password_change_required_at');
            $table->foreignUuid('created_by_organization_id')->nullable()->after('temporary_password_expires_at')
                ->constrained('organizations')->nullOnDelete();
        });

        DB::statement('ALTER TABLE users ADD CONSTRAINT users_temporary_password_check CHECK (temporary_password_expires_at IS NULL OR password_change_required_at IS NOT NULL)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_temporary_password_check');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_organization_id');
            $table->dropColumn(['password_change_required_at', 'temporary_password_expires_at']);
        });
    }
};
