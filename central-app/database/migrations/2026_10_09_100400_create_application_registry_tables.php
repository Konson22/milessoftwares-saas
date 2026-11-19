<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The registry is the single source of truth for application definitions:
     * marketing catalog status, gateway routing, OIDC clients and the role and
     * permission catalogs all hang off these rows.
     */
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category', 32);
            $table->string('kind', 24);
            $table->string('status', 16)->default('planned');
            // Workspace module path, e.g. "retail" for {slug}.milessoftwares.com/retail
            $table->string('route_prefix', 32)->nullable()->unique();
            $table->string('gateway_upstream')->nullable();
            $table->string('integration_endpoint')->nullable();
            $table->string('provisioning_method', 16)->default('none');
            $table->jsonb('capabilities')->nullable();
            // FK added after application_catalog_versions exists.
            $table->uuid('current_catalog_version_id')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE applications ADD CONSTRAINT applications_code_check CHECK (code ~ '^[a-z][a-z0-9_]*$')");
        DB::statement("ALTER TABLE applications ADD CONSTRAINT applications_kind_check CHECK (kind IN ('standalone', 'shared_module', 'industry', 'addon'))");
        DB::statement("ALTER TABLE applications ADD CONSTRAINT applications_status_check CHECK (status IN ('planned', 'beta', 'available', 'deprecated', 'retired'))");
        DB::statement("ALTER TABLE applications ADD CONSTRAINT applications_provisioning_method_check CHECK (provisioning_method IN ('none', 'manual', 'sync_api', 'async_api'))");

        // OIDC/OAuth clients belonging to an application. Secrets live with the
        // OAuth server (Phase 2); this row links the registry to that client.
        Schema::create('application_clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('application_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('client_identifier', 100)->unique();
            $table->string('client_type', 16);
            $table->string('oauth_client_ref', 100)->nullable()->unique();
            $table->string('status', 16)->default('active');
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE application_clients ADD CONSTRAINT application_clients_type_check CHECK (client_type IN ('confidential', 'public', 'service'))");
        DB::statement("ALTER TABLE application_clients ADD CONSTRAINT application_clients_status_check CHECK (status IN ('active', 'disabled'))");

        // Exact-match redirect URIs. Per-tenant URIs carry organization_id and are
        // written by provisioning; no wildcard matching anywhere.
        Schema::create('application_client_redirect_uris', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('application_client_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('uri', 2048);
            $table->timestampsTz();

            $table->unique(['application_client_id', 'uri']);
        });

        Schema::create('application_catalog_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('application_id')->constrained()->cascadeOnDelete();
            $table->string('version', 32);
            $table->string('status', 16)->default('draft');
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();

            $table->unique(['application_id', 'version']);
            $table->unique(['application_id', 'id']);
        });

        DB::statement("ALTER TABLE application_catalog_versions ADD CONSTRAINT application_catalog_versions_status_check CHECK (status IN ('draft', 'published', 'retired'))");

        Schema::table('applications', function (Blueprint $table) {
            $table->foreign(['id', 'current_catalog_version_id'])->references(['application_id', 'id'])->on('application_catalog_versions');
        });

        Schema::create('application_permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('application_id');
            $table->uuid('catalog_version_id');
            $table->string('key', 100);
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sensitivity')->default(1);
            $table->timestampsTz();

            $table->unique(['catalog_version_id', 'key']);
            $table->unique(['catalog_version_id', 'id']);
            $table->foreign(['application_id', 'catalog_version_id'])->references(['application_id', 'id'])->on('application_catalog_versions')->cascadeOnDelete();
        });

        Schema::create('application_roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('application_id');
            $table->uuid('catalog_version_id');
            $table->string('key', 100);
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_delegable')->default(true);
            $table->unsignedSmallInteger('sensitivity')->default(1);
            $table->timestampsTz();

            $table->unique(['catalog_version_id', 'key']);
            $table->unique(['catalog_version_id', 'id']);
            $table->unique(['application_id', 'id']);
            $table->foreign(['application_id', 'catalog_version_id'])->references(['application_id', 'id'])->on('application_catalog_versions')->cascadeOnDelete();
        });

        // Scope types are normalized rows, not JSON, so they can be constrained.
        foreach (['application_permission' => 'application_permissions', 'application_role' => 'application_roles'] as $prefix => $parent) {
            Schema::create("{$prefix}_scope_types", function (Blueprint $table) use ($prefix, $parent) {
                $table->foreignUuid("{$prefix}_id")->constrained($parent)->cascadeOnDelete();
                $table->string('scope_type', 16);

                $table->primary(["{$prefix}_id", 'scope_type']);
            });

            DB::statement("ALTER TABLE {$prefix}_scope_types ADD CONSTRAINT {$prefix}_scope_types_check CHECK (scope_type IN ('organization', 'branch', 'department', 'resource'))");
        }

        // Both sides must belong to the same catalog version.
        Schema::create('role_permission_mappings', function (Blueprint $table) {
            $table->uuid('catalog_version_id');
            $table->uuid('application_role_id');
            $table->uuid('application_permission_id');

            $table->primary(['application_role_id', 'application_permission_id']);
            $table->foreign(['catalog_version_id', 'application_role_id'])->references(['catalog_version_id', 'id'])->on('application_roles')->cascadeOnDelete();
            $table->foreign(['catalog_version_id', 'application_permission_id'])->references(['catalog_version_id', 'id'])->on('application_permissions')->cascadeOnDelete();
        });

        // Separation-of-duties pairs declared by an application's catalog.
        Schema::create('sod_constraints', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('catalog_version_id');
            $table->uuid('permission_a_id');
            $table->uuid('permission_b_id');
            $table->string('mode', 8)->default('block');
            $table->text('description')->nullable();
            $table->timestampsTz();

            $table->unique(['catalog_version_id', 'permission_a_id', 'permission_b_id']);
            $table->foreign(['catalog_version_id', 'permission_a_id'])->references(['catalog_version_id', 'id'])->on('application_permissions')->cascadeOnDelete();
            $table->foreign(['catalog_version_id', 'permission_b_id'])->references(['catalog_version_id', 'id'])->on('application_permissions')->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE sod_constraints ADD CONSTRAINT sod_constraints_mode_check CHECK (mode IN ('warn', 'block'))");
        // Ordered pair prevents storing (a,b) and (b,a) as two rows.
        DB::statement('ALTER TABLE sod_constraints ADD CONSTRAINT sod_constraints_ordered_pair_check CHECK (permission_a_id < permission_b_id)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('applications', fn (Blueprint $table) => $table->dropForeign(['id', 'current_catalog_version_id']));

        Schema::dropIfExists('sod_constraints');
        Schema::dropIfExists('role_permission_mappings');
        Schema::dropIfExists('application_role_scope_types');
        Schema::dropIfExists('application_permission_scope_types');
        Schema::dropIfExists('application_roles');
        Schema::dropIfExists('application_permissions');
        Schema::dropIfExists('application_catalog_versions');
        Schema::dropIfExists('application_client_redirect_uris');
        Schema::dropIfExists('application_clients');
        Schema::dropIfExists('applications');
    }
};
