<?php

use App\Logbook\ResourceCategories;
use App\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * Asset categories (ADR-0031 addendum): a workspace's list of broad groups
 * (Vehicles, Generators, Cooling…) above each asset's type (Car, Pickup).
 *
 * - log_resource_categories: per workspace, started from the defaults in
 *   App\Logbook\ResourceCategories; never deleted, switched off instead.
 * - log_resources.category_id and log_asset_templates.category_id.
 * - Backfill: every workspace with assets or templates gets the defaults,
 *   and each asset/template the category its type falls in.
 *
 * Additive: `type` stays as it was, and log sheets keep matching it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('log_resource_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            // The default it started as (vehicles, generators…); null for a workspace's own.
            $table->string('key', 32)->nullable();
            $table->string('name', 60);
            $table->string('description', 200)->nullable();
            // Types suggested in it when registering (Car, Pickup…).
            $table->json('types')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            TenantSchema::key($table);
            $table->unique(['organization_id', 'name']);
        });

        foreach (['log_resources', 'log_asset_templates'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->uuid('category_id')->nullable();
                TenantSchema::foreign($table, 'category_id', 'log_resource_categories');
                $table->index(['organization_id', 'category_id']);
            });
        }

        $organizations = DB::table('log_resources')->distinct()->pluck('organization_id')
            ->merge(DB::table('log_asset_templates')->distinct()->pluck('organization_id'))->unique();
        $now = Carbon::now();
        foreach ($organizations as $organizationId) {
            $ids = [];
            $sort = 0;
            foreach (ResourceCategories::DEFAULTS as $key => $default) {
                $ids[$key] = (string) Str::uuid7();
                DB::table('log_resource_categories')->insert([
                    'id' => $ids[$key], 'organization_id' => $organizationId, 'key' => $key, 'name' => $default['name'],
                    'description' => $default['description'], 'types' => json_encode($default['types']),
                    'sort' => $sort += 10, 'active' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            foreach (['log_resources', 'log_asset_templates'] as $name) {
                foreach (DB::table($name)->where('organization_id', $organizationId)->get(['id', 'type']) as $row) {
                    DB::table($name)->where('id', $row->id)->update(['category_id' => $ids[ResourceCategories::guessKey($row->type)]]);
                }
            }
        }
    }

    public function down(): void
    {
        foreach (['log_asset_templates', 'log_resources'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropForeign($table->getTable().'_category_id_tenant_fk');
                $table->dropIndex(['organization_id', 'category_id']);
                $table->dropColumn('category_id');
            });
        }
        Schema::dropIfExists('log_resource_categories');
    }
};
