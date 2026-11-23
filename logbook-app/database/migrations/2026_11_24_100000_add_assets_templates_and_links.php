<?php

use App\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * ADR-0031: assets registered from templates, linked to the log sheets
 * that record them, scheduled one by one, and named on each entry.
 *
 * - log_resources gains a kind (a physical asset, or a monitored place
 *   such as a cold room), a status (active, inactive, retired), identity
 *   details, free metadata, and the template it was registered from (a
 *   snapshot: changing the template later never changes the asset).
 * - log_asset_templates: an organization's own asset templates. The
 *   starter templates live in code (App\Logbook\AssetTemplates).
 * - log_sheet_resources: which assets a sheet records (many to many).
 * - Schedules and their occurrences may be for one asset: an entry for
 *   another asset never meets them.
 * - log_entries.resource_id: the asset the entry is about, from the
 *   sheet's main resource field, kept at every save. Backfilled here.
 *
 * Everything is additive; `active` stays as it was and follows status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('log_asset_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('name', 120);
            // The category a new asset takes (Generator, Vehicle...).
            $table->string('type', 48);
            $table->string('kind', 24)->default('asset');
            $table->text('description')->nullable();
            // Extra details asked when registering: [{name, label, type, unit?}].
            $table->json('fields')->nullable();
            // What is measured on it (free words: temperature, running hours...).
            $table->json('measurements')->nullable();
            // Default fuel estimate: {method: distance|hours, fuel_type}.
            $table->json('fuel')->nullable();
            // Starter log sheet keys suggested for it.
            $table->json('suggested_sheets')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('active')->default(true);
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();
            TenantSchema::key($table);
            $table->unique(['organization_id', 'name']);
        });

        Schema::table('log_resources', function (Blueprint $table) {
            $table->string('kind', 24)->default('asset');
            $table->string('status', 16)->default('active');
            $table->string('manufacturer', 120)->nullable();
            $table->string('model', 120)->nullable();
            $table->string('serial_number', 120)->nullable();
            $table->foreignUuid('responsible_id')->nullable()->constrained('users');
            $table->json('metadata')->nullable();
            // {key, name, version, fields}: the template as it was when the asset was registered.
            $table->json('template')->nullable();
            $table->index(['organization_id', 'status']);
        });
        DB::table('log_resources')->where('active', false)->update(['status' => 'inactive']);

        Schema::create('log_sheet_resources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('log_sheet_id');
            $table->uuid('log_resource_id');
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();
            TenantSchema::key($table);
            TenantSchema::foreign($table, 'log_sheet_id', 'log_sheets');
            TenantSchema::foreign($table, 'log_resource_id', 'log_resources');
            $table->unique(['organization_id', 'log_sheet_id', 'log_resource_id'], 'log_sheet_resources_unique');
            $table->index(['organization_id', 'log_resource_id']);
        });

        foreach (['log_schedules', 'log_schedule_occurrences', 'log_entries'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->uuid('resource_id')->nullable();
                TenantSchema::foreign($table, 'resource_id', 'log_resources');
            });
        }
        Schema::table('log_schedule_occurrences', fn (Blueprint $table) => $table->index(['organization_id', 'resource_id', 'due_at']));
        Schema::table('log_entries', fn (Blueprint $table) => $table->index(['organization_id', 'resource_id', 'occurred_at']));

        $this->backfillEntries();
    }

    /**
     * The asset of entries already written: the sheet version's fuel
     * resource field, else its only resource field. Versions with several
     * resource fields and no fuel setting are left unset: nobody said
     * which one the entry is about.
     */
    private function backfillEntries(): void
    {
        $versions = DB::table('log_sheet_versions')->get(['id', 'fields', 'recording']);
        foreach ($versions as $version) {
            $fields = json_decode((string) $version->fields, true) ?: [];
            $recording = json_decode((string) ($version->recording ?? 'null'), true);
            $resourceFields = array_values(array_map(fn ($f) => $f['name'], array_filter($fields, fn ($f) => ($f['type'] ?? null) === 'resource')));
            $subject = $recording['activity']['resource_field'] ?? null;
            if (! in_array($subject, $resourceFields, true)) {
                $subject = count($resourceFields) === 1 ? $resourceFields[0] : null;
            }
            if ($subject === null) {
                continue;
            }
            DB::table('log_entries')->where('log_sheet_version_id', $version->id)->orderBy('id')
                ->chunk(500, function ($entries) use ($subject) {
                    foreach ($entries as $entry) {
                        $values = json_decode((string) $entry->values, true) ?: [];
                        $id = $values[$subject] ?? null;
                        if (is_string($id) && DB::table('log_resources')->where('id', $id)->where('organization_id', $entry->organization_id)->exists()) {
                            DB::table('log_entries')->where('id', $entry->id)->update(['resource_id' => $id]);
                        }
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::table('log_entries', fn (Blueprint $table) => $table->dropIndex(['organization_id', 'resource_id', 'occurred_at']));
        Schema::table('log_schedule_occurrences', fn (Blueprint $table) => $table->dropIndex(['organization_id', 'resource_id', 'due_at']));
        foreach (['log_entries', 'log_schedule_occurrences', 'log_schedules'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropForeign($table->getTable().'_resource_id_tenant_fk');
                $table->dropColumn('resource_id');
            });
        }
        Schema::dropIfExists('log_sheet_resources');
        Schema::table('log_resources', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'status']);
            $table->dropForeign(['responsible_id']);
            $table->dropColumn(['kind', 'status', 'manufacturer', 'model', 'serial_number', 'responsible_id', 'metadata', 'template']);
        });
        Schema::dropIfExists('log_asset_templates');
    }
};
