<?php

use App\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Partner reports and project requests (ADR-0015). organization_id is the
 * project's owner; a party is the owner (no participant) or a participant.
 * Submitted report versions are frozen; reviews and request decisions are
 * append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_obligations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUuid('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignUuid('responsible_participant_id')->nullable()->constrained('project_participants')->restrictOnDelete();
            $table->foreignUuid('reviewer_participant_id')->nullable()->constrained('project_participants')->restrictOnDelete();
            $table->string('category', 24);
            $table->string('title');
            $table->string('frequency', 16);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedSmallInteger('due_days');
            $table->boolean('publish_after_approval')->default(false);
            $table->string('status', 16)->default('active');
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['project_id', 'status']);
        });

        Schema::create('project_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUuid('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignUuid('obligation_id')->nullable()->constrained('report_obligations')->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->string('category', 24);
            $table->string('title');
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->date('due_on')->nullable();
            $table->foreignUuid('submitter_participant_id')->nullable()->constrained('project_participants')->restrictOnDelete();
            $table->foreignUuid('reviewer_participant_id')->nullable()->constrained('project_participants')->restrictOnDelete();
            $table->string('status', 24)->default('draft');
            $table->uuid('current_version_id')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignUuid('published_by')->nullable()->constrained('users');
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();

            // One report per obligation period: generating twice adds nothing.
            $table->unique(['obligation_id', 'period_start']);
            $table->unique(['project_id', 'number']);
            $table->index(['project_id', 'status']);
        });

        Schema::create('project_report_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('report_id')->constrained('project_reports')->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->json('content');
            $table->foreignUuid('created_by')->constrained('users');
            $table->foreignUuid('submitted_by')->nullable()->constrained('users');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->unique(['report_id', 'number']);
        });

        Schema::table('project_reports', function (Blueprint $table) {
            $table->foreign('current_version_id')->references('id')->on('project_report_versions')->restrictOnDelete();
        });

        Schema::create('project_report_reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('report_id')->constrained('project_reports')->restrictOnDelete();
            $table->foreignUuid('version_id')->nullable()->constrained('project_report_versions')->restrictOnDelete();
            $table->string('action', 24);
            $table->text('comment')->nullable();
            $table->foreignUuid('user_id')->constrained('users');
            $table->foreignUuid('acting_organization_id')->constrained('organizations');
            $table->timestamp('created_at');

            $table->index(['report_id', 'created_at']);
        });

        Schema::create('project_report_sources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('report_id')->constrained('project_reports')->restrictOnDelete();
            $table->foreignUuid('source_report_id')->constrained('project_reports')->restrictOnDelete();
            $table->foreignUuid('added_by')->constrained('users');
            $table->timestamp('created_at');

            $table->unique(['report_id', 'source_report_id']);
        });

        Schema::create('project_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUuid('project_id')->constrained('projects')->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->string('type', 24);
            $table->string('title');
            $table->text('justification');
            $table->json('details');
            $table->bigInteger('amount_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->foreignUuid('submitter_participant_id')->nullable()->constrained('project_participants')->restrictOnDelete();
            $table->foreignUuid('reviewer_participant_id')->nullable()->constrained('project_participants')->restrictOnDelete();
            $table->string('status', 24)->default('draft');
            $table->foreignUuid('raised_by')->constrained('users');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignUuid('decided_by')->nullable()->constrained('users');
            $table->foreignUuid('budget_version_id')->nullable()->constrained('budget_versions')->restrictOnDelete();
            $table->foreignUuid('grant_disbursement_id')->nullable()->constrained('grant_disbursements')->restrictOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'number']);
            $table->index(['project_id', 'status']);
        });

        Schema::create('project_request_decisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('request_id')->constrained('project_requests')->restrictOnDelete();
            $table->string('action', 24);
            $table->text('comment')->nullable();
            // What was asked, as submitted: what is approved is what was asked.
            $table->json('snapshot')->nullable();
            $table->foreignUuid('user_id')->constrained('users');
            $table->foreignUuid('acting_organization_id')->constrained('organizations');
            $table->timestamp('created_at');

            $table->index(['request_id', 'created_at']);
        });

        Schema::create('collaboration_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('attachable_type', 32);
            $table->uuid('attachable_id');
            $table->string('name');
            $table->string('path');
            $table->string('mime', 128)->nullable();
            $table->unsignedBigInteger('size');
            $table->foreignUuid('uploaded_by')->constrained('users');
            $table->foreignUuid('acting_organization_id')->constrained('organizations');
            $table->timestamp('created_at');

            $table->index(['attachable_type', 'attachable_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            foreach (['report_obligations', 'project_reports', 'project_requests'] as $name) {
                Schema::table($name, fn (Blueprint $table) => TenantSchema::foreign($table, 'project_id', 'projects'));
            }

            DB::statement("ALTER TABLE report_obligations ADD CONSTRAINT report_obligations_frequency_check CHECK (frequency IN ('monthly', 'quarterly', 'annual', 'once', 'custom'))");
            DB::statement("ALTER TABLE project_reports ADD CONSTRAINT project_reports_status_check CHECK (status IN ('draft', 'submitted', 'under_review', 'changes_requested', 'approved', 'published', 'rejected', 'withdrawn'))");
            DB::statement("ALTER TABLE project_requests ADD CONSTRAINT project_requests_type_check CHECK (type IN ('funding', 'budget_revision', 'activity_change', 'timeline_extension', 'information'))");
            DB::statement("ALTER TABLE project_requests ADD CONSTRAINT project_requests_status_check CHECK (status IN ('draft', 'submitted', 'under_review', 'changes_requested', 'approved', 'rejected', 'cancelled'))");
            // A participant never reviews itself (the owner may review internally).
            DB::statement('ALTER TABLE project_reports ADD CONSTRAINT project_reports_parties_check CHECK (submitter_participant_id IS NULL OR submitter_participant_id IS DISTINCT FROM reviewer_participant_id)');
            DB::statement('ALTER TABLE project_requests ADD CONSTRAINT project_requests_parties_check CHECK (submitter_participant_id IS NULL OR submitter_participant_id IS DISTINCT FROM reviewer_participant_id)');

            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION collaboration_append_only() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION '% is append-only', TG_TABLE_NAME;
                END;
                $$ LANGUAGE plpgsql
            SQL);
            DB::statement('CREATE TRIGGER project_report_reviews_append_only BEFORE UPDATE OR DELETE ON project_report_reviews FOR EACH ROW EXECUTE FUNCTION collaboration_append_only()');
            DB::statement('CREATE TRIGGER project_request_decisions_append_only BEFORE UPDATE OR DELETE ON project_request_decisions FOR EACH ROW EXECUTE FUNCTION collaboration_append_only()');

            // A submitted version is frozen.
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION project_report_versions_frozen() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'DELETE' OR OLD.submitted_at IS NOT NULL THEN
                        RAISE EXCEPTION 'a submitted report version cannot be changed; make a new version';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql
            SQL);
            DB::statement('CREATE TRIGGER project_report_versions_frozen BEFORE UPDATE OR DELETE ON project_report_versions FOR EACH ROW EXECUTE FUNCTION project_report_versions_frozen()');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS project_report_versions_frozen ON project_report_versions');
            DB::statement('DROP TRIGGER IF EXISTS project_request_decisions_append_only ON project_request_decisions');
            DB::statement('DROP TRIGGER IF EXISTS project_report_reviews_append_only ON project_report_reviews');
            DB::statement('DROP FUNCTION IF EXISTS project_report_versions_frozen()');
            DB::statement('DROP FUNCTION IF EXISTS collaboration_append_only()');
        }
        Schema::dropIfExists('collaboration_attachments');
        Schema::dropIfExists('project_request_decisions');
        Schema::dropIfExists('project_requests');
        Schema::dropIfExists('project_report_sources');
        Schema::dropIfExists('project_report_reviews');
        Schema::table('project_reports', fn (Blueprint $table) => $table->dropForeign(['current_version_id']));
        Schema::dropIfExists('project_report_versions');
        Schema::dropIfExists('project_reports');
        Schema::dropIfExists('report_obligations');
    }
};
