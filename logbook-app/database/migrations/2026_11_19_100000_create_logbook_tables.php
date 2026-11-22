<?php

use App\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Miles Logbook (ADR-0025): logbooks, log sheets with immutable template
 * versions, access lists, entries with their submitted revisions, reviews,
 * attachments, reference sequences and the audit trail.
 *
 * Every table is tenant keyed: a child points at its parent through
 * (organization_id, id), so no row can reference another organization's.
 * Entry values are JSON, read through the template version the entry was
 * written against; adding a field never adds a column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logbooks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('icon', 32)->nullable();
            $table->string('status', 16)->default('active');
            // Restricted: only members on the access list (and managers) reach it.
            $table->boolean('restricted')->default(false);
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestampTz('archived_at')->nullable();
            $table->timestamps();
            TenantSchema::key($table);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('log_sheets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('logbook_id');
            $table->string('name', 120);
            // Prefix of entry references: GEN-2026-000001. Unique per organization.
            $table->string('code', 12);
            $table->text('description')->nullable();
            $table->text('instructions')->nullable();
            // draft (never published), active, archived.
            $table->string('status', 16)->default('draft');
            $table->boolean('review_required')->default(false);
            $table->uuid('published_version_id')->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestampTz('archived_at')->nullable();
            $table->timestamps();
            TenantSchema::key($table);
            TenantSchema::foreign($table, 'logbook_id', 'logbooks');
            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'logbook_id', 'status']);
        });

        Schema::create('log_sheet_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('log_sheet_id');
            $table->unsignedInteger('version');
            // draft (one per sheet at most, editable) or published (immutable).
            $table->string('status', 16)->default('draft');
            $table->json('fields');
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestampTz('published_at')->nullable();
            $table->foreignUuid('published_by')->nullable()->constrained('users');
            $table->timestamps();
            TenantSchema::key($table);
            TenantSchema::foreign($table, 'log_sheet_id', 'log_sheets');
            $table->unique(['log_sheet_id', 'version']);
        });

        Schema::table('log_sheets', function (Blueprint $table) {
            TenantSchema::foreign($table, 'published_version_id', 'log_sheet_versions');
        });

        Schema::create('logbook_access', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('logbook_id');
            // Null: the whole logbook; otherwise that sheet only.
            $table->uuid('log_sheet_id')->nullable();
            $table->foreignUuid('user_id')->constrained('users');
            // contributor, reviewer, viewer.
            $table->string('role', 16);
            $table->foreignUuid('granted_by')->constrained('users');
            $table->timestamps();
            TenantSchema::key($table);
            TenantSchema::foreign($table, 'logbook_id', 'logbooks');
            TenantSchema::foreign($table, 'log_sheet_id', 'log_sheets');
            $table->index(['organization_id', 'user_id']);
            $table->index(['organization_id', 'logbook_id']);
        });

        Schema::create('log_sequences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('code', 12);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last');
            $table->timestamps();
            $table->unique(['organization_id', 'code', 'year']);
        });

        Schema::create('log_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('logbook_id');
            $table->uuid('log_sheet_id');
            // The template the values are read with; changes only while a draft
            // or returned entry is edited against a newer published version.
            $table->uuid('log_sheet_version_id');
            // Drawn at first submission: drafts leave no gaps in the sequence.
            $table->string('reference', 32)->nullable();
            // draft, submitted, returned, reviewed, finalized.
            $table->string('status', 16)->default('draft');
            // Fixed at submission from the sheet's setting.
            $table->boolean('review_required')->default(false);
            $table->timestampTz('occurred_at');
            $table->json('values');
            // Lowercased reference and answers, for keyword search.
            $table->text('search_text')->nullable();
            // Submitted revisions so far (0 for a draft never submitted).
            $table->unsignedInteger('revision')->default(0);
            $table->foreignUuid('created_by')->constrained('users');
            $table->string('idempotency_key', 64)->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users');
            $table->timestamps();
            TenantSchema::key($table);
            TenantSchema::foreign($table, 'logbook_id', 'logbooks');
            TenantSchema::foreign($table, 'log_sheet_id', 'log_sheets');
            TenantSchema::foreign($table, 'log_sheet_version_id', 'log_sheet_versions');
            $table->unique(['organization_id', 'reference']);
            $table->unique(['organization_id', 'created_by', 'idempotency_key']);
            $table->index(['organization_id', 'log_sheet_id', 'occurred_at']);
            $table->index(['organization_id', 'logbook_id', 'occurred_at']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'created_by', 'status']);
        });

        // What was submitted, each time: never changed afterwards.
        Schema::create('log_entry_revisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('log_entry_id');
            $table->unsignedInteger('revision');
            $table->uuid('log_sheet_version_id');
            $table->timestampTz('occurred_at');
            $table->json('values');
            $table->foreignUuid('submitted_by')->constrained('users');
            $table->timestampTz('submitted_at');
            TenantSchema::key($table);
            TenantSchema::foreign($table, 'log_entry_id', 'log_entries');
            TenantSchema::foreign($table, 'log_sheet_version_id', 'log_sheet_versions');
            $table->unique(['log_entry_id', 'revision']);
        });

        Schema::create('log_entry_reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('log_entry_id');
            $table->unsignedInteger('revision');
            // reviewed or returned.
            $table->string('decision', 16);
            $table->text('comment')->nullable();
            $table->foreignUuid('reviewer_id')->constrained('users');
            $table->string('reviewer_name');
            $table->timestampTz('decided_at');
            TenantSchema::key($table);
            TenantSchema::foreign($table, 'log_entry_id', 'log_entries');
            $table->index(['organization_id', 'log_entry_id']);
        });

        Schema::create('log_entry_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('log_entry_id');
            // The file field it answers.
            $table->string('field', 64);
            $table->string('name');
            $table->string('path');
            $table->unsignedInteger('size');
            $table->string('mime', 128)->nullable();
            $table->foreignUuid('uploaded_by')->constrained('users');
            // Taken off the entry after being part of a submitted revision: kept for history.
            $table->timestampTz('removed_at')->nullable();
            $table->timestamps();
            TenantSchema::key($table);
            TenantSchema::foreign($table, 'log_entry_id', 'log_entries');
            $table->index(['organization_id', 'log_entry_id']);
        });

        Schema::create('log_audit_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('action', 48);
            $table->string('subject_type', 32);
            $table->uuid('subject_id')->nullable();
            $table->string('subject_label');
            $table->uuid('logbook_id')->nullable();
            $table->foreignUuid('user_id')->nullable()->constrained('users');
            $table->string('user_name')->nullable();
            $table->json('details')->nullable();
            $table->timestampTz('created_at');
            $table->index(['organization_id', 'created_at']);
            $table->index(['organization_id', 'subject_type', 'subject_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION logbook_append_only() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION '% is append-only', TG_TABLE_NAME;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER log_audit_events_append_only BEFORE UPDATE OR DELETE ON log_audit_events
                    FOR EACH ROW EXECUTE FUNCTION logbook_append_only();
                CREATE TRIGGER log_entry_revisions_append_only BEFORE UPDATE OR DELETE ON log_entry_revisions
                    FOR EACH ROW EXECUTE FUNCTION logbook_append_only();
                CREATE TRIGGER log_entry_reviews_append_only BEFORE UPDATE OR DELETE ON log_entry_reviews
                    FOR EACH ROW EXECUTE FUNCTION logbook_append_only();

                CREATE OR REPLACE FUNCTION log_sheet_versions_published_immutable() RETURNS trigger AS $$
                BEGIN
                    IF OLD.status = 'published' THEN
                        RAISE EXCEPTION 'a published log sheet version is immutable';
                    END IF;
                    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER log_sheet_versions_published_immutable BEFORE UPDATE OR DELETE ON log_sheet_versions
                    FOR EACH ROW EXECUTE FUNCTION log_sheet_versions_published_immutable();

                ALTER TABLE logbooks ADD CONSTRAINT logbooks_status_check CHECK (status IN ('active', 'archived'));
                ALTER TABLE log_sheets ADD CONSTRAINT log_sheets_status_check CHECK (status IN ('draft', 'active', 'archived'));
                ALTER TABLE log_sheet_versions ADD CONSTRAINT log_sheet_versions_status_check CHECK (status IN ('draft', 'published'));
                ALTER TABLE logbook_access ADD CONSTRAINT logbook_access_role_check CHECK (role IN ('contributor', 'reviewer', 'viewer'));
                ALTER TABLE log_entries ADD CONSTRAINT log_entries_status_check CHECK (status IN ('draft', 'submitted', 'returned', 'reviewed', 'finalized'));
                ALTER TABLE log_entries ADD CONSTRAINT log_entries_reference_check CHECK (status = 'draft' OR reference IS NOT NULL);
                ALTER TABLE log_entry_reviews ADD CONSTRAINT log_entry_reviews_decision_check CHECK (decision IN ('reviewed', 'returned'));

                CREATE UNIQUE INDEX log_sheet_versions_one_draft ON log_sheet_versions (log_sheet_id) WHERE status = 'draft';
                CREATE UNIQUE INDEX logbook_access_one_grant ON logbook_access (logbook_id, COALESCE(log_sheet_id, logbook_id), user_id);
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS log_audit_events_append_only ON log_audit_events;
                DROP TRIGGER IF EXISTS log_entry_revisions_append_only ON log_entry_revisions;
                DROP TRIGGER IF EXISTS log_entry_reviews_append_only ON log_entry_reviews;
                DROP TRIGGER IF EXISTS log_sheet_versions_published_immutable ON log_sheet_versions;
                SQL);
        }

        Schema::table('log_sheets', fn (Blueprint $table) => $table->dropForeign('log_sheets_published_version_id_tenant_fk'));

        foreach ([
            'log_audit_events', 'log_entry_attachments', 'log_entry_reviews', 'log_entry_revisions',
            'log_entries', 'log_sequences', 'logbook_access', 'log_sheet_versions', 'log_sheets', 'logbooks',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS logbook_append_only(); DROP FUNCTION IF EXISTS log_sheet_versions_published_immutable();');
        }
    }
};
