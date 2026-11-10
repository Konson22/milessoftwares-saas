<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What someone signed up as (a donor, or an organization that runs its own
 * work), so their first organization is set up in the right workspace; and the
 * role a donor gives the person it invites to run an organization it funds.
 * Both additive: accounts and invitations made before stay as they were.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // donor | organization; null for accounts made by an invitation.
            $table->string('account_type', 16)->nullable()->after('status');
        });

        Schema::table('partnerships', function (Blueprint $table) {
            // A role key (config access.roles); null: owner only.
            $table->string('role', 64)->nullable()->after('label');
        });
    }

    public function down(): void
    {
        Schema::table('partnerships', fn (Blueprint $table) => $table->dropColumn('role'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('account_type'));
    }
};
