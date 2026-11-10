<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Projects is a platform product again: the platform signs people in and
 * holds organizations, members and roles (platform/catalog.json). Users and
 * organizations stay as copies keyed by the platform's ids; a membership
 * keeps the access last seen from the platform, for work without a request.
 * Passwords, two-factor, Google sign-in, the app's own roles and the public
 * website's inquiries go.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_memberships', function (Blueprint $table) {
            $table->json('permissions')->nullable();
            $table->json('roles')->nullable();
        });

        Schema::dropIfExists('role_assignments');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('inquiries');

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['google_id']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['password', 'google_id', 'account_type', 'is_platform_admin', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'deactivated_at']);
        });
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('two_factor_policy');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('two_factor_policy', 16)->default('off');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable();
            $table->string('google_id')->nullable()->unique();
            $table->string('account_type', 16)->nullable();
            $table->boolean('is_platform_admin')->default(false);
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
        // The roles tables and inquiries are not brought back: run the
        // standalone app's migrations from its backup for those.

        Schema::table('organization_memberships', function (Blueprint $table) {
            $table->dropColumn(['permissions', 'roles']);
        });
    }
};
