<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data collection M4 (ADR-0019 §21): collected submissions arrive, are
 * reviewed and exported.
 *
 * - collect_submissions.source: how it arrived (mobile; web is reserved for
 *   later, the MVP has no web data entry, ADR-0012 §14).
 * - An index for the phone's incremental status sync (its own submissions
 *   changed since a moment).
 * - collect_exports.answers: current (corrections applied) or original.
 * - PostgreSQL: CHECK constraints on the statuses, and a trigger that refuses
 *   a correction of an accepted or rejected submission (reopen it first).
 *
 * Additive only: no data is changed or removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collect_submissions', function (Blueprint $table) {
            $table->string('source', 8)->default('mobile');
            $table->index(['organization_id', 'author_id', 'updated_at'], 'collect_submissions_author_sync');
        });

        Schema::table('collect_exports', function (Blueprint $table) {
            $table->string('answers', 8)->default('current');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE collect_submissions ADD CONSTRAINT collect_submissions_status_check CHECK (status IN ('receiving', 'submitted', 'under_review', 'correction_requested', 'accepted', 'rejected'))");
        DB::statement("ALTER TABLE collect_submissions ADD CONSTRAINT collect_submissions_source_check CHECK (source IN ('mobile', 'web'))");
        DB::statement('ALTER TABLE collect_submissions ADD CONSTRAINT collect_submissions_attachments_check CHECK (attachments_received <= attachments_expected)');
        DB::statement("ALTER TABLE collect_assignments ADD CONSTRAINT collect_assignments_status_check CHECK (status IN ('active', 'closing', 'revoked'))");
        DB::statement("ALTER TABLE collect_review_decisions ADD CONSTRAINT collect_review_decisions_action_check CHECK (action IN ('received', 'review_started', 'accepted', 'rejected', 'correction_requested', 'corrected', 'reopened'))");
        DB::statement("ALTER TABLE collect_exports ADD CONSTRAINT collect_exports_status_check CHECK (status IN ('queued', 'ready', 'failed', 'expired'))");
        DB::statement("ALTER TABLE collect_exports ADD CONSTRAINT collect_exports_answers_check CHECK (answers IN ('current', 'original'))");

        // A decided submission is locked: a correction needs a reopen first.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION collect_revisions_not_decided() RETURNS trigger AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM collect_submissions s WHERE s.id = NEW.submission_id AND s.status IN ('accepted', 'rejected')) THEN
                    RAISE EXCEPTION 'an accepted or rejected submission is locked; reopen it to correct it';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('CREATE TRIGGER collect_revisions_not_decided BEFORE INSERT ON collect_submission_revisions FOR EACH ROW EXECUTE FUNCTION collect_revisions_not_decided()');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS collect_revisions_not_decided ON collect_submission_revisions');
            DB::statement('DROP FUNCTION IF EXISTS collect_revisions_not_decided()');
            foreach ([
                'collect_submissions' => ['collect_submissions_status_check', 'collect_submissions_source_check', 'collect_submissions_attachments_check'],
                'collect_assignments' => ['collect_assignments_status_check'],
                'collect_review_decisions' => ['collect_review_decisions_action_check'],
                'collect_exports' => ['collect_exports_status_check', 'collect_exports_answers_check'],
            ] as $table => $constraints) {
                foreach ($constraints as $constraint) {
                    DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraint}");
                }
            }
        }

        Schema::table('collect_exports', fn (Blueprint $table) => $table->dropColumn('answers'));
        Schema::table('collect_submissions', function (Blueprint $table) {
            $table->dropIndex('collect_submissions_author_sync');
            $table->dropColumn('source');
        });
    }
};
