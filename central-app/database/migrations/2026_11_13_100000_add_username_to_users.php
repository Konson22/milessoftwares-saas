<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts without an email: a business's administrator adds a member (a
 * pump attendant, say) with a username, and they sign in with username and
 * password. Every account keeps one of the two. Usernames are unique on the
 * platform, compared case-insensitively like emails.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 32)->nullable()->after('email');
            $table->string('email')->nullable()->change();
        });

        DB::statement('CREATE UNIQUE INDEX users_username_lower_unique ON users (lower(username))');
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_email_or_username CHECK (email IS NOT NULL OR username IS NOT NULL)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_email_or_username');
        DB::statement('DROP INDEX IF EXISTS users_username_lower_unique');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('username');
            $table->string('email')->nullable(false)->change();
        });
    }
};
