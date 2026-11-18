<?php

use App\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Collected data → indicators (ADR-0012 §11, ADR-0019 §26), and the work
 * a form serves in Tatook Projects.
 *
 * A mapping says how a form's accepted submissions make one indicator's
 * value. A calculation run applies it to one reporting period and unit and
 * sends the value to M&E as a draft result (source "collect"); M&E's own
 * verification takes it from there. The rows record which submission (and
 * which correction of it) went into the run, so a verified result can be
 * traced back to its answers, and "standing" rows make sure one submission
 * counts once per indicator.
 *
 * Additive only: nothing existing is changed or dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collect_indicator_mappings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('form_id');
            $table->uuid('indicator_id');
            // count | sum | average | percentage
            $table->string('calculation', 16);
            // The number summed or averaged (sum, average).
            $table->string('question', 64)->nullable();
            // Which accepted submissions count at all (a §4 condition), null: all.
            $table->jsonb('condition')->nullable();
            // percentage: which of those are in the numerator.
            $table->jsonb('numerator_condition')->nullable();
            // A stable identifier (household or beneficiary id): repeated
            // visits count once, by the latest one. Null: every submission counts.
            $table->string('distinct_by', 64)->nullable();
            $table->text('note')->nullable();
            // active | retired
            $table->string('status', 16);
            // A mapping a sent run used is never edited: a change makes a new one.
            $table->uuid('supersedes_id')->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->foreignUuid('retired_by')->nullable()->constrained('users');
            $table->timestampTz('retired_at')->nullable();
            $table->timestamps();

            TenantSchema::key($table);
            TenantSchema::foreign($table, 'form_id', 'collect_forms');
            TenantSchema::foreign($table, 'indicator_id', 'mel_indicators');
            TenantSchema::foreign($table, 'supersedes_id', 'collect_indicator_mappings');
            $table->index(['organization_id', 'indicator_id']);
        });
        // One live mapping per indicator and form: the same answers are never added twice.
        DB::statement("CREATE UNIQUE INDEX collect_indicator_mappings_one_active ON collect_indicator_mappings (indicator_id, form_id) WHERE status = 'active'");

        Schema::create('collect_calculation_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('mapping_id');
            $table->uuid('indicator_id');
            $table->uuid('reporting_period_id');
            $table->uuid('programme_unit_id');
            $table->decimal('value', 20, 6)->nullable();
            $table->decimal('numerator', 20, 6)->nullable();
            $table->decimal('denominator', 20, 6)->nullable();
            // Submissions of the form in the period and unit, accepted ones, and those used.
            $table->unsignedInteger('considered');
            $table->unsignedInteger('accepted');
            $table->unsignedInteger('used');
            // {reason: count} for the ones left out.
            $table->json('excluded');
            // sent | superseded (a later run for the same period and unit replaced it)
            $table->string('status', 16);
            // The M&E result it made or updated.
            $table->uuid('mel_submission_id')->nullable();
            // Set when a submission it counted was reopened or corrected afterwards.
            $table->timestampTz('flagged_at')->nullable();
            $table->string('flag_reason', 255)->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();

            TenantSchema::key($table);
            TenantSchema::foreign($table, 'mapping_id', 'collect_indicator_mappings');
            TenantSchema::foreign($table, 'indicator_id', 'mel_indicators');
            TenantSchema::foreign($table, 'reporting_period_id', 'mel_reporting_periods');
            TenantSchema::foreign($table, 'programme_unit_id', 'mel_programme_units');
            TenantSchema::foreign($table, 'mel_submission_id', 'mel_submissions');
            $table->index(['indicator_id', 'reporting_period_id', 'programme_unit_id']);
        });

        Schema::create('collect_calculation_rows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('run_id');
            $table->uuid('indicator_id');
            $table->uuid('submission_id');
            // The correction the answers came from (null: the original answers).
            $table->uuid('revision_id')->nullable();
            // What it added: 1 for a count, the number for a sum or an average.
            $table->decimal('amount', 20, 6);
            // percentage: whether it is in the numerator.
            $table->boolean('in_numerator')->nullable();
            // False once its run is superseded.
            $table->boolean('standing')->default(true);

            TenantSchema::key($table);
            TenantSchema::foreign($table, 'run_id', 'collect_calculation_runs');
            TenantSchema::foreign($table, 'indicator_id', 'mel_indicators');
            TenantSchema::foreign($table, 'submission_id', 'collect_submissions');
            TenantSchema::foreign($table, 'revision_id', 'collect_submission_revisions');
            $table->index(['submission_id', 'standing']);
        });
        // No submission counts twice for one indicator.
        DB::statement('CREATE UNIQUE INDEX collect_calculation_rows_once ON collect_calculation_rows (indicator_id, submission_id) WHERE standing');

        // The milestone (a work item of a project linked to the programme) a
        // form or an assignment serves. A milestone in Tatook Projects: an id
        // only, never a foreign key (ADR-0019 §4).
        Schema::table('collect_forms', function (Blueprint $table) {
            $table->uuid('linked_milestone_id')->nullable();
        });
        Schema::table('collect_assignments', function (Blueprint $table) {
            $table->uuid('linked_milestone_id')->nullable();
            // How many submissions are expected from this person (null: not set).
            $table->unsignedInteger('expected_submissions')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('collect_assignments', function (Blueprint $table) {
            $table->dropColumn(['linked_milestone_id', 'expected_submissions']);
        });
        Schema::table('collect_forms', function (Blueprint $table) {
            $table->dropColumn('linked_milestone_id');
        });
        Schema::dropIfExists('collect_calculation_rows');
        Schema::dropIfExists('collect_calculation_runs');
        Schema::dropIfExists('collect_indicator_mappings');
    }
};
