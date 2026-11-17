<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A withdrawn donor invitation was deleted. It is kept now, marked withdrawn,
 * so the history of who was invited stays (Phase 5, invitations). Additive:
 * existing rows are untouched and read as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partnerships', function (Blueprint $table) {
            $table->timestamp('revoked_at')->nullable()->after('declined_by');
            $table->uuid('revoked_by')->nullable()->after('revoked_at');
            $table->index(['donor_organization_id', 'email']);
        });
    }

    public function down(): void
    {
        if (DB::table('partnerships')->whereNotNull('revoked_at')->exists()) {
            throw new RuntimeException('Refusing to roll back: withdrawn invitations would come back as live ones.');
        }

        Schema::table('partnerships', function (Blueprint $table) {
            $table->dropIndex(['donor_organization_id', 'email']);
            $table->dropColumn(['revoked_at', 'revoked_by']);
        });
    }
};
