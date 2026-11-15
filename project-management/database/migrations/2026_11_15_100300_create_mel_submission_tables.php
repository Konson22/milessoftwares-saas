<?php

use App\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Actual results and their verification (ADR-0011 §3, §5):
 *
 * draft → submitted → under_review → verified → published, with rejected
 * going back to the submitter. A verified value is copied, frozen, into
 * verified_results, which every report reads. A correction is a new
 * revision; the old verified result stands until the revision is verified.
 */
return new class extends Migration
{
    private const NONE = "'00000000-0000-0000-0000-000000000000'";

    public function up(): void
    {
        Schema::create('mel_import_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('programme_id');
            $table->uuid('reporting_period_id');
            $table->string('file_name');
            $table->char('sha256', 64);
            // The uploaded file on the private disk, until applied.
            $table->string('path')->nullable();
            $table->string('status', 16); // validated | applied | failed
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_ready')->default(0);
            $table->unsignedInteger('rows_skipped')->default(0);
            $table->json('errors')->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestampTz('applied_at')->nullable();
            $table->timestamps();

            TenantSchema::key($table);
            TenantSchema::foreign($table, 'programme_id', 'mel_programmes');
            TenantSchema::foreign($table, 'reporting_period_id', 'mel_reporting_periods');
            $table->index(['organization_id', 'sha256']);
        });

        Schema::create('mel_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('programme_id');
            $table->uuid('indicator_id');
            // The definition the value was reported under.
            $table->uuid('indicator_version_id');
            $table->uuid('reporting_period_id');
            $table->uuid('unit_id');
            $table->decimal('value', 20, 6)->nullable();
            $table->decimal('numerator', 20, 6)->nullable();
            $table->decimal('denominator', 20, 6)->nullable();
            $table->text('narrative')->nullable();
            // Why the value is off target, when it is.
            $table->text('deviation_note')->nullable();
            // draft | submitted | under_review | rejected | verified | published | superseded
            $table->string('status', 16);
            $table->unsignedSmallInteger('revision')->default(1);
            $table->uuid('supersedes_id')->nullable();
            $table->string('source', 16)->default('manual'); // manual | import | kobo | odk
            // The source's own id for the row (file hash + row, Kobo uuid):
            // the same row imported twice is the same submission.
            $table->string('source_reference', 128)->nullable();
            $table->uuid('import_batch_id')->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->foreignUuid('submitted_by')->nullable()->constrained('users');
            $table->timestampTz('submitted_at')->nullable();
            $table->foreignUuid('reviewer_id')->nullable()->constrained('users');
            $table->timestamps();

            TenantSchema::key($table);
            TenantSchema::foreign($table, 'programme_id', 'mel_programmes');
            TenantSchema::foreign($table, 'indicator_id', 'mel_indicators');
            TenantSchema::foreign($table, 'indicator_version_id', 'mel_indicator_versions');
            TenantSchema::foreign($table, 'reporting_period_id', 'mel_reporting_periods');
            TenantSchema::foreign($table, 'unit_id', 'mel_programme_units');
            TenantSchema::foreign($table, 'supersedes_id', 'mel_submissions');
            TenantSchema::foreign($table, 'import_batch_id', 'mel_import_batches');
            $table->unique(['organization_id', 'source', 'source_reference']);
            $table->index(['organization_id', 'status']);
            $table->index(['programme_id', 'reporting_period_id']);
        });

        // At most one submission in progress, and one verified or published,
        // per indicator, period and unit: duplicates are refused.
        DB::statement("CREATE UNIQUE INDEX submissions_one_open ON mel_submissions (indicator_id, reporting_period_id, unit_id) WHERE status IN ('draft', 'submitted', 'under_review', 'rejected')");
        DB::statement("CREATE UNIQUE INDEX submissions_one_standing ON mel_submissions (indicator_id, reporting_period_id, unit_id) WHERE status IN ('verified', 'published')");

        // Disaggregated cells: one per category (or pair of categories).
        Schema::create('mel_submission_values', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('submission_id');
            $table->uuid('breakdown_id');
            $table->uuid('category_id');
            $table->uuid('second_category_id')->nullable();
            $table->decimal('value', 20, 6)->nullable();
            $table->decimal('numerator', 20, 6)->nullable();
            $table->decimal('denominator', 20, 6)->nullable();

            TenantSchema::key($table);
            TenantSchema::foreign($table, 'submission_id', 'mel_submissions');
            TenantSchema::foreign($table, 'breakdown_id', 'mel_indicator_breakdowns');
            TenantSchema::foreign($table, 'category_id', 'mel_dimension_categories');
            TenantSchema::foreign($table, 'second_category_id', 'mel_dimension_categories');
        });
        DB::statement('CREATE UNIQUE INDEX submission_values_cell ON mel_submission_values (submission_id, breakdown_id, category_id, COALESCE(second_category_id, '.self::NONE.'))');

        // Every step of a submission's review. Append-only.
        Schema::create('mel_review_decisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('submission_id');
            // submitted | review_started | verified | rejected | published | correction_opened
            $table->string('action', 24);
            $table->text('comment')->nullable();
            $table->foreignUuid('user_id')->constrained('users');
            $table->timestampTz('created_at');

            TenantSchema::key($table);
            TenantSchema::foreign($table, 'submission_id', 'mel_submissions');
            $table->index(['submission_id', 'created_at']);
        });

        // The frozen copy of a verified value. Reports read only this table.
        Schema::create('mel_verified_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('submission_id')->unique();
            $table->uuid('programme_id');
            $table->uuid('indicator_id');
            $table->uuid('indicator_version_id');
            $table->uuid('reporting_period_id');
            $table->uuid('unit_id');
            $table->decimal('value', 20, 6)->nullable();
            $table->decimal('numerator', 20, 6)->nullable();
            $table->decimal('denominator', 20, 6)->nullable();
            // The disaggregated cells as verified.
            $table->json('cells')->nullable();
            $table->foreignUuid('verified_by')->constrained('users');
            $table->timestampTz('verified_at');
            $table->foreignUuid('published_by')->nullable()->constrained('users');
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('superseded_at')->nullable();
            $table->timestampTz('created_at');

            TenantSchema::key($table);
            TenantSchema::foreign($table, 'submission_id', 'mel_submissions');
            TenantSchema::foreign($table, 'programme_id', 'mel_programmes');
            TenantSchema::foreign($table, 'indicator_id', 'mel_indicators');
            TenantSchema::foreign($table, 'indicator_version_id', 'mel_indicator_versions');
            TenantSchema::foreign($table, 'reporting_period_id', 'mel_reporting_periods');
            TenantSchema::foreign($table, 'unit_id', 'mel_programme_units');
            $table->index(['programme_id', 'reporting_period_id']);
        });
        DB::statement('CREATE UNIQUE INDEX verified_results_standing ON mel_verified_results (indicator_id, reporting_period_id, unit_id) WHERE superseded_at IS NULL');

        // Supporting documents, on the private disk, read only through M&E.
        Schema::create('mel_evidence_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('programme_id');
            $table->uuid('submission_id')->nullable();
            $table->uuid('result_id')->nullable();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            // Donor viewers see it once the result is published.
            $table->boolean('share_with_donors')->default(false);
            $table->foreignUuid('uploaded_by')->constrained('users');
            $table->timestamps();

            TenantSchema::key($table);
            TenantSchema::foreign($table, 'programme_id', 'mel_programmes');
            TenantSchema::foreign($table, 'submission_id', 'mel_submissions');
            TenantSchema::foreign($table, 'result_id', 'mel_results');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION mel_review_decisions_append_only() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'review decisions are append-only';
                END;
                $$ LANGUAGE plpgsql
            SQL);
            DB::statement('CREATE TRIGGER mel_review_decisions_append_only BEFORE UPDATE OR DELETE ON mel_review_decisions FOR EACH ROW EXECUTE FUNCTION mel_review_decisions_append_only()');

            // A verified result's values never change: only its publication
            // and its replacement by a verified correction are recorded.
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION mel_verified_results_frozen() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'verified results are never deleted';
                    END IF;
                    IF (NEW.value, NEW.numerator, NEW.denominator, NEW.cells::text, NEW.submission_id, NEW.indicator_version_id,
                        NEW.reporting_period_id, NEW.unit_id, NEW.verified_by, NEW.verified_at)
                       IS DISTINCT FROM
                       (OLD.value, OLD.numerator, OLD.denominator, OLD.cells::text, OLD.submission_id, OLD.indicator_version_id,
                        OLD.reporting_period_id, OLD.unit_id, OLD.verified_by, OLD.verified_at) THEN
                        RAISE EXCEPTION 'a verified result cannot be changed; submit a correction';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql
            SQL);
            DB::statement('CREATE TRIGGER mel_verified_results_frozen BEFORE UPDATE OR DELETE ON mel_verified_results FOR EACH ROW EXECUTE FUNCTION mel_verified_results_frozen()');

            // A version in use by a submission is never rewritten.
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION mel_indicator_versions_in_use() RETURNS trigger AS $$
                BEGIN
                    IF EXISTS (SELECT 1 FROM mel_submissions WHERE indicator_version_id = OLD.id) THEN
                        RAISE EXCEPTION 'this indicator version has results reported under it; create a new version';
                    END IF;
                    IF TG_OP = 'DELETE' THEN
                        RETURN OLD;
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql
            SQL);
            DB::statement('CREATE TRIGGER mel_indicator_versions_in_use BEFORE UPDATE OR DELETE ON mel_indicator_versions FOR EACH ROW EXECUTE FUNCTION mel_indicator_versions_in_use()');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS mel_indicator_versions_in_use ON mel_indicator_versions');
            DB::statement('DROP FUNCTION IF EXISTS mel_indicator_versions_in_use()');
            DB::statement('DROP TRIGGER IF EXISTS mel_verified_results_frozen ON mel_verified_results');
            DB::statement('DROP FUNCTION IF EXISTS mel_verified_results_frozen()');
            DB::statement('DROP TRIGGER IF EXISTS mel_review_decisions_append_only ON mel_review_decisions');
            DB::statement('DROP FUNCTION IF EXISTS mel_review_decisions_append_only()');
        }

        foreach (['mel_evidence_files', 'mel_verified_results', 'mel_review_decisions', 'mel_submission_values', 'mel_submissions', 'mel_import_batches'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
