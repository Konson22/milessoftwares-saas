<?php

use App\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Investigations (ADR-0029): a small incident review linked to the
 * original records, never a copy of them.
 *
 * - log_investigations: reference INV-YYYY-NNNNNN, the logbook it belongs
 *   to (who may open it follows that logbook), the item and time of the
 *   incident, the period looked at, the investigator, the status
 *   (open, investigating, findings_recorded, closed), the closure decision
 *   and a revision for stale-write checks.
 * - log_investigation_evidence: a pointer to an entry (and the exact
 *   revision looked at), an exception, a task or a route, with who added it
 *   and when. Only a short label (the record's reference) is stored; the
 *   record itself stays where it is and is read with the viewer's own
 *   access. Removing evidence marks it removed, with a reason: nothing is
 *   deleted. `role` tells evidence from corrective actions (tasks).
 * - log_investigation_notes: observations, hypotheses, findings,
 *   conclusions and follow-up. A change is a new row that supersedes the
 *   previous one, so every wording stays readable.
 *
 * Additive only; down() removes exactly these tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('log_investigations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('reference', 32);
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->uuid('logbook_id');
            $table->uuid('resource_id')->nullable();
            // When the incident happened, as far as is known.
            $table->timestampTz('occurred_at')->nullable();
            // The period looked at (workspace dates).
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->foreignUuid('investigator_id')->constrained('users');
            $table->string('status', 20)->default('open');
            $table->text('closure_decision')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->foreignUuid('closed_by')->nullable()->constrained('users');
            $table->timestampTz('reopened_at')->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            TenantSchema::key($table);
            TenantSchema::foreign($table, 'logbook_id', 'logbooks');
            TenantSchema::foreign($table, 'resource_id', 'log_resources');
            $table->unique(['organization_id', 'reference']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'logbook_id']);
        });

        Schema::create('log_investigation_evidence', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('investigation_id');
            // entry, exception, task, track.
            $table->string('kind', 16);
            $table->uuid('subject_id');
            // For an entry: the submitted revision looked at.
            $table->unsignedInteger('entry_revision')->nullable();
            // The subject's logbook, kept for access checks on the list.
            $table->uuid('logbook_id')->nullable();
            // The record's reference only (e.g. GEN-2026-000004): never its values.
            $table->string('label', 200);
            // evidence, corrective.
            $table->string('role', 12)->default('evidence');
            $table->text('note')->nullable();
            $table->foreignUuid('added_by')->constrained('users');
            $table->timestampTz('added_at');
            $table->timestampTz('removed_at')->nullable();
            $table->foreignUuid('removed_by')->nullable()->constrained('users');
            $table->string('removal_reason', 500)->nullable();
            TenantSchema::key($table);
            TenantSchema::foreign($table, 'investigation_id', 'log_investigations');
            $table->index(['organization_id', 'investigation_id']);
            $table->index(['organization_id', 'kind', 'subject_id']);
        });

        Schema::create('log_investigation_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('investigation_id');
            // observation, hypothesis, finding, conclusion, follow_up.
            $table->string('kind', 16);
            $table->text('body');
            $table->unsignedInteger('version')->default(1);
            // The note this wording replaces; the replaced row is kept.
            $table->uuid('supersedes_id')->nullable();
            $table->timestampTz('superseded_at')->nullable();
            $table->foreignUuid('author_id')->constrained('users');
            $table->timestampTz('created_at');
            TenantSchema::key($table);
            TenantSchema::foreign($table, 'investigation_id', 'log_investigations');
            TenantSchema::foreign($table, 'supersedes_id', 'log_investigation_notes');
            $table->index(['organization_id', 'investigation_id', 'kind']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE log_investigations ADD CONSTRAINT log_investigations_status_check CHECK (status IN ('open', 'investigating', 'findings_recorded', 'closed'));
                ALTER TABLE log_investigation_evidence ADD CONSTRAINT log_investigation_evidence_kind_check CHECK (kind IN ('entry', 'exception', 'task', 'track'));
                ALTER TABLE log_investigation_evidence ADD CONSTRAINT log_investigation_evidence_role_check CHECK (role IN ('evidence', 'corrective'));
                ALTER TABLE log_investigation_notes ADD CONSTRAINT log_investigation_notes_kind_check CHECK (kind IN ('observation', 'hypothesis', 'finding', 'conclusion', 'follow_up'));
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('log_investigation_notes');
        Schema::dropIfExists('log_investigation_evidence');
        Schema::dropIfExists('log_investigations');
    }
};
