<?php

use App\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Programmes, their results frameworks and indicators (ADR-0011 §3).
 *
 * Indicator values are numeric(20,6) and are calculated with bcmath, never
 * floats. Baselines and targets are never edited: a change is a new row
 * that supersedes the old one, with a reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Places programmes work in: country > state > county > ...
        Schema::create('mel_locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('parent_id')->nullable();
            $table->string('name', 120);
            $table->string('code', 32)->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestamps();

            TenantSchema::key($table);
            TenantSchema::foreign($table, 'parent_id', 'mel_locations');
            $table->unique(['organization_id', 'parent_id', 'name']);
        });

        // Implementing organizations (partners) that run part of a programme.
        Schema::create('mel_partners', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('name', 160);
            $table->string('kind', 24)->default('ngo'); // ngo | government | community | private | un_agency | other
            $table->timestampTz('archived_at')->nullable();
            $table->timestamps();

            TenantSchema::key($table);
            $table->unique(['organization_id', 'name']);
        });

        // Disaggregation dimensions (Sex, Age group, ...) and their categories.
        // Location and partner are not dimensions: they come from the unit
        // a value is reported for.
        Schema::create('mel_dimensions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('name', 80);
            // Hidden from donor views unless the result is published with it.
            $table->boolean('is_sensitive')->default(false);
            $table->timestampTz('archived_at')->nullable();
            $table->timestamps();

            TenantSchema::key($table);
            $table->unique(['organization_id', 'name']);
        });

        Schema::create('mel_dimension_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('dimension_id');
            $table->string('name', 80);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestampTz('archived_at')->nullable();
            $table->timestamps();

            TenantSchema::key($table);
            TenantSchema::foreign($table, 'dimension_id', 'mel_dimensions');
            $table->unique(['dimension_id', 'name']);
        });

        Schema::create('mel_programmes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('code', 24);
            $table->string('name', 160);
            $table->text('objective')->nullable();
            $table->text('description')->nullable();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 16)->default('draft'); // draft | active | closed
            $table->char('currency', 3);
            // Declared funding, for context. Not accounting.
            $table->bigInteger('budget_minor')->nullable();
            $table->text('funding_summary')->nullable();
            $table->foreignUuid('manager_id')->nullable()->constrained('users');
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestampTz('archived_at')->nullable();
            $table->timestamps();

            TenantSchema::key($table);
            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('mel_programme_locations', function (Blueprint $table) {
            $table->uuid('organization_id');
            $table->uuid('programme_id');
            $table->uuid('location_id');

            $table->primary(['programme_id', 'location_id']);
            TenantSchema::foreign($table, 'programme_id', 'mel_programmes');
            TenantSchema::foreign($table, 'location_id', 'mel_locations');
        });

        // What results are reported for: a project, a site, a partner's part.
        Schema::create('mel_programme_units', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('programme_id');
            $table->string('code', 24);
            $table->string('name', 160);
            $table->string('kind', 16)->default('project'); // project | site | partner | other
            $table->uuid('partner_id')->nullable();
            $table->uuid('location_id')->nullable();
            // One Projects project is linked to at most one unit of a programme.
            $table->uuid('linked_project_id')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestamps();

            TenantSchema::key($table);
            TenantSchema::foreign($table, 'programme_id', 'mel_programmes');
            TenantSchema::foreign($table, 'partner_id', 'mel_partners');
            TenantSchema::foreign($table, 'location_id', 'mel_locations');
            // linked_project_id is a project in Tatook Projects, another application with its own
            // database: an id only, never a foreign key (ADR-0019 §4).
            $table->unique(['programme_id', 'code']);
            $table->unique(['programme_id', 'linked_project_id']);
        });

        Schema::create('mel_reporting_periods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('programme_id');
            $table->string('frequency', 16); // monthly | quarterly | semiannual | annual | custom
            $table->string('label', 48);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->date('due_on');
            $table->string('status', 16)->default('open'); // open | closed
            $table->timestamps();

            TenantSchema::key($table);
            TenantSchema::foreign($table, 'programme_id', 'mel_programmes');
            // Overlaps within a frequency are refused by App\Mel\Periods
            // under a lock on the programme.
            $table->unique(['programme_id', 'frequency', 'starts_on']);
            $table->index(['organization_id', 'due_on']);
        });

        // Access to one programme for people without mel.programmes.all:
        // field officers, partners (one unit), donors and evaluators (a
        // window of periods).
        Schema::create('mel_programme_access', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('programme_id');
            $table->foreignUuid('user_id')->constrained('users');
            $table->uuid('unit_id')->nullable();
            // Donors and evaluators: only periods within these dates.
            $table->date('periods_from')->nullable();
            $table->date('periods_to')->nullable();
            $table->date('expires_on')->nullable();
            $table->foreignUuid('granted_by')->constrained('users');
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignUuid('revoked_by')->nullable()->constrained('users');
            $table->timestamps();

            TenantSchema::key($table);
            TenantSchema::foreign($table, 'programme_id', 'mel_programmes');
            TenantSchema::foreign($table, 'unit_id', 'mel_programme_units');
            $table->index(['organization_id', 'user_id']);
        });

        // The framework's levels, renamed and reordered per programme:
        // Goal > Outcome > Output > Activity by default.
        Schema::create('mel_result_levels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('programme_id');
            $table->unsignedTinyInteger('depth'); // 1 = top
            $table->string('name', 48);
            $table->timestamps();

            TenantSchema::key($table);
            TenantSchema::foreign($table, 'programme_id', 'mel_programmes');
            $table->unique(['programme_id', 'depth']);
        });

        Schema::create('mel_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('programme_id');
            $table->uuid('level_id');
            $table->uuid('parent_id')->nullable();
            $table->string('code', 24); // "1", "1.2", "1.2.3"
            $table->text('statement');
            $table->text('description')->nullable();
            $table->text('assumptions')->nullable();
            $table->text('risks')->nullable();
            $table->text('means_of_verification')->nullable();
            $table->foreignUuid('responsible_id')->nullable()->constrained('users');
            $table->uuid('linked_milestone_id')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestampTz('archived_at')->nullable();
            $table->timestamps();

            TenantSchema::key($table);
            TenantSchema::foreign($table, 'programme_id', 'mel_programmes');
            TenantSchema::foreign($table, 'level_id', 'mel_result_levels');
            TenantSchema::foreign($table, 'parent_id', 'mel_results');
            // linked_milestone_id: a milestone in Tatook Projects, an id only (ADR-0019 §4).
            $table->unique(['programme_id', 'code']);
        });

        // An indicator's identity. What it measures and how lives in its
        // versions, so a revision never rewrites what was reported before.
        Schema::create('mel_indicators', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('programme_id');
            $table->uuid('result_id');
            $table->string('code', 32);
            $table->string('status', 16)->default('draft'); // draft | active | retired
            $table->string('frequency', 16); // the reporting periods it is reported in
            $table->foreignUuid('responsible_id')->nullable()->constrained('users');
            $table->uuid('current_version_id')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            TenantSchema::key($table);
            TenantSchema::foreign($table, 'programme_id', 'mel_programmes');
            TenantSchema::foreign($table, 'result_id', 'mel_results');
            $table->unique(['programme_id', 'code']);
        });

        Schema::create('mel_indicator_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('indicator_id');
            $table->unsignedSmallInteger('version');
            $table->string('name');
            $table->text('definition')->nullable();
            $table->string('type', 16); // input | process | output | outcome | impact
            // count | number | percentage | rate | ratio | currency | yes_no
            $table->string('measure', 16);
            $table->string('unit_label', 48)->nullable(); // "people", "households", "USD"
            $table->unsignedTinyInteger('decimals')->default(0);
            // Percentages, rates and ratios: what is counted over what.
            $table->string('numerator_label')->nullable();
            $table->string('denominator_label')->nullable();
            $table->unsignedInteger('rate_multiplier')->nullable(); // rate per 1,000 => 1000
            $table->text('calculation_method')->nullable();
            $table->string('direction', 16); // increase | decrease | maintain
            // How values combine over periods: sum | latest | max | min | recompute | none
            $table->string('time_aggregation', 16);
            // How values combine across units: sum | recompute | none
            $table->string('unit_aggregation', 16);
            $table->string('data_source')->nullable();
            $table->string('collection_method')->nullable();
            $table->text('means_of_verification')->nullable();
            $table->text('change_reason')->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestampTz('created_at');

            TenantSchema::key($table);
            TenantSchema::foreign($table, 'indicator_id', 'mel_indicators');
            $table->unique(['indicator_id', 'version']);
        });

        Schema::table('mel_indicators', function (Blueprint $table) {
            TenantSchema::foreign($table, 'current_version_id', 'mel_indicator_versions');
        });

        // The disaggregation a version asks for: by one dimension, or two
        // crossed (Sex x Age group). The rule says how the cells relate to the
        // total; nothing is assumed to add up.
        Schema::create('mel_indicator_breakdowns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('indicator_version_id');
            $table->uuid('dimension_id');
            $table->uuid('second_dimension_id')->nullable();
            $table->string('rule', 24); // must_equal_total | at_most_total | none
            $table->timestamps();

            TenantSchema::key($table);
            TenantSchema::foreign($table, 'indicator_version_id', 'mel_indicator_versions');
            TenantSchema::foreign($table, 'dimension_id', 'mel_dimensions');
            TenantSchema::foreign($table, 'second_dimension_id', 'mel_dimensions');
        });

        foreach (['mel_indicator_baselines', 'mel_indicator_targets'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name) {
                $table->uuid('id')->primary();
                $table->uuid('organization_id');
                $table->uuid('indicator_id');
                // Null: for the programme as a whole.
                $table->uuid('unit_id')->nullable();
                if ($name === 'mel_indicator_targets') {
                    $table->uuid('reporting_period_id');
                }
                $table->decimal('value', 20, 6)->nullable();
                $table->decimal('numerator', 20, 6)->nullable();
                $table->decimal('denominator', 20, 6)->nullable();
                if ($name === 'mel_indicator_targets') {
                    // "Maintain" indicators: an acceptable range.
                    $table->decimal('min_value', 20, 6)->nullable();
                    $table->decimal('max_value', 20, 6)->nullable();
                } else {
                    $table->date('measured_on');
                    $table->string('source')->nullable();
                }
                $table->text('notes')->nullable();
                $table->uuid('supersedes_id')->nullable();
                $table->timestampTz('superseded_at')->nullable();
                $table->text('change_reason')->nullable();
                $table->foreignUuid('created_by')->constrained('users');
                $table->timestamps();

                TenantSchema::key($table);
                TenantSchema::foreign($table, 'indicator_id', 'mel_indicators');
                TenantSchema::foreign($table, 'unit_id', 'mel_programme_units');
                TenantSchema::foreign($table, 'supersedes_id', $name);
                if ($name === 'mel_indicator_targets') {
                    TenantSchema::foreign($table, 'reporting_period_id', 'mel_reporting_periods');
                }
            });
        }

        // One live baseline per indicator and unit, one live target per
        // indicator, period and unit (unit null = whole programme).
        $none = "'00000000-0000-0000-0000-000000000000'";
        DB::statement("CREATE UNIQUE INDEX mel_indicator_baselines_live ON mel_indicator_baselines (indicator_id, COALESCE(unit_id, {$none})) WHERE superseded_at IS NULL");
        DB::statement("CREATE UNIQUE INDEX mel_indicator_targets_live ON mel_indicator_targets (indicator_id, reporting_period_id, COALESCE(unit_id, {$none})) WHERE superseded_at IS NULL");
    }

    public function down(): void
    {
        // indicators and indicator_versions point at each other.
        Schema::table('mel_indicators', fn (Blueprint $table) => $table->dropForeign(
            DB::getDriverName() === 'sqlite' ? ['organization_id', 'current_version_id'] : 'mel_indicators_current_version_id_tenant_fk',
        ));

        foreach ([
            'mel_indicator_targets', 'mel_indicator_baselines', 'mel_indicator_breakdowns', 'mel_indicator_versions', 'mel_indicators',
            'mel_results', 'mel_result_levels', 'mel_programme_access', 'mel_reporting_periods', 'mel_programme_units', 'mel_programme_locations',
            'mel_programmes', 'mel_dimension_categories', 'mel_dimensions', 'mel_partners', 'mel_locations',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
