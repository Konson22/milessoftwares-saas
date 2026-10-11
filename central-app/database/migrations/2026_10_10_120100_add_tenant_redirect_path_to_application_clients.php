<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A client served on every tenant host (e.g. "/retail/auth/callback") gets
     * one exact redirect URI row per organization that may use it. The path
     * is the template; rows are written by TenantRedirectUris.
     */
    public function up(): void
    {
        Schema::table('application_clients', function (Blueprint $table) {
            $table->string('tenant_redirect_path', 255)->nullable();
        });

        DB::statement("ALTER TABLE application_clients ADD CONSTRAINT application_clients_tenant_redirect_path_check CHECK (tenant_redirect_path IS NULL OR tenant_redirect_path ~ '^/[A-Za-z0-9/_.-]*$')");

        // The workspace shell's client was the first per-tenant client.
        DB::table('application_clients')->where('client_identifier', 'workspace-web')->update(['tenant_redirect_path' => '/auth/callback']);
    }

    public function down(): void
    {
        Schema::table('application_clients', function (Blueprint $table) {
            $table->dropColumn('tenant_redirect_path');
        });
    }
};
