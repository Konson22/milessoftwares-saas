<?php

use App\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data collection (ADR-0012): survey forms and their published versions,
 * field assignments, collected submissions with their corrections, photos,
 * review decisions and exports.
 *
 * A published version never changes; a submission keeps the version it
 * was filled in on, and its original answers never change either
 * (corrections are revisions). Collection is separate from M&E's own
 * "submissions" (indicator values): collected data reaches an indicator
 * only through a calculation that M&E then verifies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->boolean('collect_reviewer_must_differ')->default(true);
            $table->unsignedInteger('collect_photo_max_kb')->default(800);
            $table->unsignedSmallInteger('collect_keep_on_device_days')->default(30);
            $table->unsignedSmallInteger('collect_retention_months')->nullable();
        });

        Schema::create('collect_forms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            // Optional: a form may serve a programme (and one of its units), or stand alone.
            $table->uuid('programme_id')->nullable();
            $table->uuid('programme_unit_id')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            // survey | assessment | monitoring_visit | inspection | research | other
            $table->string('purpose', 24);
            // draft (never published) | published | archived
            $table->string('status', 16);
            // The working copy, edited freely; publishing copies it into a version.
            $table->jsonb('draft_definition');
            $table->timestampTz('draft_updated_at');
            $table->foreignUuid('draft_updated_by')->nullable()->constrained('users');
            $table->uuid('current_version_id')->nullable();
            $table->boolean('contains_personal_data')->default(true);
            // Archived forms refuse new submissions after this moment (null: at once).
            $table->timestampTz('accept_until')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();

            TenantSchema::key($table);
            TenantSchema::foreign($table, 'programme_id', 'programmes');
            TenantSchema::foreign($table, 'programme_unit_id', 'programme_units');
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'programme_id']);
        });

        Schema::create('collect_form_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('form_id');
            $table->unsignedSmallInteger('number');
            $table->jsonb('definition');
            $table->char('sha256', 64);
            $table->text('note')->nullable();
            $table->foreignUuid('published_by')->constrained('users');
            $table->timestampTz('published_at');
            // Closed: no longer offered to phones. Submissions are still
            // accepted until accept_until (null: none after closing).
            $table->timestampTz('closed_at')->nullable();
            $table->timestampTz('accept_until')->nullable();

            TenantSchema::key($table);
            $table->foreign(['organization_id', 'form_id'], 'collect_form_versions_form_id_tenant_fk')
                ->references(['organization_id', 'id'])->on('collect_forms')->restrictOnDelete();
            $table->unique(['form_id', 'number']);
        });

        Schema::table('collect_forms', function (Blueprint $table) {
            TenantSchema::foreign($table, 'current_version_id', 'collect_form_versions');
        });

        // Who collects with which form. Without an assignment, nobody's
        // phone gets the form, whatever their permissions.
        Schema::create('collect_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('form_id');
            $table->foreignUuid('user_id')->constrained('users');
            // The unit the phone proposes for this person's submissions.
            $table->uuid('programme_unit_id')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            // active | closing (no new forms; finished ones accepted until closes_at) | revoked
            $table->string('status', 16);
            $table->timestampTz('closes_at')->nullable();
            $table->foreignUuid('assigned_by')->constrained('users');
            $table->foreignUuid('ended_by')->nullable()->constrained('users');
            $table->timestampTz('ended_at')->nullable();
            $table->timestamps();

            TenantSchema::key($table);
            TenantSchema::foreign($table, 'form_id', 'collect_forms');
            TenantSchema::foreign($table, 'programme_unit_id', 'programme_units');
            $table->index(['organization_id', 'user_id', 'status']);
        });
        DB::statement("CREATE UNIQUE INDEX collect_assignments_one_live ON collect_assignments (form_id, user_id) WHERE status <> 'revoked'");

        Schema::create('collect_submissions', function (Blueprint $table) {
            // Made by the phone: sending the same submission again is a no-op.
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('form_id');
            $table->uuid('form_version_id');
            $table->uuid('programme_id')->nullable();
            $table->uuid('programme_unit_id')->nullable();
            $table->foreignUuid('author_id')->constrained('users');
            // receiving (photos still coming) | submitted | under_review |
            // correction_requested | accepted | rejected
            $table->string('status', 24);
            // As the phone reported them (its clock), and when the server got it.
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at');
            $table->timestampTz('received_at');
            // The original answers, by question name. Never changed: corrections are revisions.
            $table->jsonb('answers');
            $table->char('content_hash', 64);
            // [{question, sha256, size, mime}]: the photos that belong to the answers.
            $table->json('attachments_manifest');
            $table->unsignedSmallInteger('attachments_expected')->default(0);
            $table->unsignedSmallInteger('attachments_received')->default(0);
            $table->boolean('consent_given')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->decimal('accuracy', 9, 2)->nullable();
            $table->string('app_version', 32)->nullable();
            $table->uuid('current_revision_id')->nullable();
            $table->foreignUuid('reviewer_id')->nullable()->constrained('users');
            $table->timestampTz('reviewed_at')->nullable();
            // Set by the retention job when the answers were removed.
            $table->timestampTz('purged_at')->nullable();
            $table->timestamps();

            TenantSchema::key($table);
            TenantSchema::foreign($table, 'form_id', 'collect_forms');
            $table->foreign(['organization_id', 'form_version_id'], 'collect_submissions_form_version_id_tenant_fk')
                ->references(['organization_id', 'id'])->on('collect_form_versions')->restrictOnDelete();
            TenantSchema::foreign($table, 'programme_id', 'programmes');
            TenantSchema::foreign($table, 'programme_unit_id', 'programme_units');
            $table->index(['organization_id', 'form_id', 'status']);
            $table->index(['organization_id', 'author_id', 'completed_at']);
            $table->index(['organization_id', 'programme_id', 'completed_at']);
            $table->index(['organization_id', 'received_at']);
        });

        Schema::create('collect_submission_revisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('submission_id');
            $table->unsignedSmallInteger('number');
            // The full set of answers as corrected.
            $table->jsonb('answers');
            // The question names whose answers differ from the previous answers.
            $table->json('changed');
            $table->text('reason');
            $table->foreignUuid('author_id')->constrained('users');
            $table->timestampTz('created_at');

            TenantSchema::key($table);
            TenantSchema::foreign($table, 'submission_id', 'collect_submissions');
            $table->unique(['submission_id', 'number']);
        });

        Schema::table('collect_submissions', function (Blueprint $table) {
            TenantSchema::foreign($table, 'current_revision_id', 'collect_submission_revisions');
        });

        // Photos, on the private disk, read only through M&E.
        Schema::create('collect_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('submission_id');
            $table->string('question', 64);
            $table->char('sha256', 64);
            $table->unsignedInteger('size');
            $table->string('mime_type', 64);
            $table->string('path');
            $table->timestampTz('received_at');

            TenantSchema::key($table);
            TenantSchema::foreign($table, 'submission_id', 'collect_submissions');
            $table->unique(['submission_id', 'question']);
        });

        Schema::create('collect_review_decisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('submission_id');
            // received | review_started | accepted | rejected | correction_requested | corrected | reopened
            $table->string('action', 24);
            $table->text('comment')->nullable();
            // Comments on single answers: {question name: comment}.
            $table->json('notes')->nullable();
            $table->foreignUuid('user_id')->constrained('users');
            $table->timestampTz('created_at');

            TenantSchema::key($table);
            TenantSchema::foreign($table, 'submission_id', 'collect_submissions');
            $table->index(['submission_id', 'created_at']);
        });

        Schema::create('collect_exports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('form_id');
            $table->foreignUuid('requested_by')->constrained('users');
            $table->json('filters');
            $table->string('format', 8); // csv | xlsx
            // Whether personal answers were included (the requester could see them).
            $table->boolean('includes_personal_data');
            $table->string('status', 16); // queued | ready | failed | expired
            $table->unsignedInteger('rows')->nullable();
            $table->string('path')->nullable();
            $table->string('error')->nullable();
            $table->unsignedInteger('downloads')->default(0);
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestamps();

            TenantSchema::key($table);
            TenantSchema::foreign($table, 'form_id', 'collect_forms');
            $table->index(['organization_id', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX collect_submissions_answers ON collect_submissions USING GIN (answers jsonb_path_ops)');

            // A published version is what submissions were filled in on: it never changes.
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION collect_form_versions_frozen() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'published form versions are never deleted';
                    END IF;
                    IF (NEW.definition::text, NEW.sha256, NEW.number, NEW.form_id, NEW.published_by, NEW.published_at)
                       IS DISTINCT FROM
                       (OLD.definition::text, OLD.sha256, OLD.number, OLD.form_id, OLD.published_by, OLD.published_at) THEN
                        RAISE EXCEPTION 'a published form version cannot be changed; publish a new version';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql
            SQL);
            DB::statement('CREATE TRIGGER collect_form_versions_frozen BEFORE UPDATE OR DELETE ON collect_form_versions FOR EACH ROW EXECUTE FUNCTION collect_form_versions_frozen()');

            // The collected answers never change (only the retention job may
            // empty them), and a submission is never deleted.
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION collect_submissions_original() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'collected submissions are never deleted';
                    END IF;
                    IF (NEW.form_id, NEW.form_version_id, NEW.author_id, NEW.completed_at, NEW.received_at, NEW.content_hash)
                       IS DISTINCT FROM
                       (OLD.form_id, OLD.form_version_id, OLD.author_id, OLD.completed_at, OLD.received_at, OLD.content_hash) THEN
                        RAISE EXCEPTION 'a collected submission cannot be changed; make a correction';
                    END IF;
                    IF NEW.answers::text IS DISTINCT FROM OLD.answers::text
                       AND NOT (OLD.purged_at IS NULL AND NEW.purged_at IS NOT NULL AND NEW.answers = '{}'::jsonb) THEN
                        RAISE EXCEPTION 'collected answers cannot be changed; make a correction';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql
            SQL);
            DB::statement('CREATE TRIGGER collect_submissions_original BEFORE UPDATE OR DELETE ON collect_submissions FOR EACH ROW EXECUTE FUNCTION collect_submissions_original()');

            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION collect_append_only() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION '% is append-only', TG_TABLE_NAME;
                END;
                $$ LANGUAGE plpgsql
            SQL);
            foreach (['collect_submission_revisions', 'collect_review_decisions'] as $table) {
                DB::statement("CREATE TRIGGER {$table}_append_only BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION collect_append_only()");
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (['collect_submission_revisions', 'collect_review_decisions'] as $table) {
                DB::statement("DROP TRIGGER IF EXISTS {$table}_append_only ON {$table}");
            }
            DB::statement('DROP FUNCTION IF EXISTS collect_append_only()');
            DB::statement('DROP TRIGGER IF EXISTS collect_submissions_original ON collect_submissions');
            DB::statement('DROP FUNCTION IF EXISTS collect_submissions_original()');
            DB::statement('DROP TRIGGER IF EXISTS collect_form_versions_frozen ON collect_form_versions');
            DB::statement('DROP FUNCTION IF EXISTS collect_form_versions_frozen()');
        }

        Schema::table('collect_submissions', fn (Blueprint $table) => $table->dropForeign('collect_submissions_current_revision_id_tenant_fk'));
        Schema::table('collect_forms', fn (Blueprint $table) => $table->dropForeign('collect_forms_current_version_id_tenant_fk'));

        foreach (['collect_exports', 'collect_review_decisions', 'collect_attachments', 'collect_submission_revisions', 'collect_submissions', 'collect_assignments', 'collect_form_versions', 'collect_forms'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn(['collect_reviewer_must_differ', 'collect_photo_max_kb', 'collect_keep_on_device_days', 'collect_retention_months']);
        });
    }
};
