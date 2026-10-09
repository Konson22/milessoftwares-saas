<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Platform-internal applications (the workspace shell) own OIDC clients and
     * per-tenant redirect URIs like products do, but are never listed in the
     * product catalog.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE applications DROP CONSTRAINT applications_kind_check');
        DB::statement("ALTER TABLE applications ADD CONSTRAINT applications_kind_check CHECK (kind IN ('standalone', 'shared_module', 'industry', 'addon', 'platform'))");

        // Per-tenant redirect URIs are looked up by exact value during authorization.
        DB::statement('CREATE INDEX application_client_redirect_uris_uri_index ON application_client_redirect_uris (uri)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS application_client_redirect_uris_uri_index');
        DB::statement("DELETE FROM applications WHERE kind = 'platform'");
        DB::statement('ALTER TABLE applications DROP CONSTRAINT applications_kind_check');
        DB::statement("ALTER TABLE applications ADD CONSTRAINT applications_kind_check CHECK (kind IN ('standalone', 'shared_module', 'industry', 'addon'))");
    }
};
