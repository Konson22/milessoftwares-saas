<?php

use App\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Organizations taking part in another organization's project (ADR-0014).
 * organization_id is the project's owner, as everywhere; the participating
 * organization is participant_organization_id. Participations are never
 * deleted: an ended one stays as history. Also: what members of a project
 * team are responsible for, the department they stand for, and an optional
 * manager of a department.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_participants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUuid('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignUuid('participant_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->string('relationship', 24);
            $table->uuid('parent_participant_id')->nullable();
            $table->json('capabilities');
            $table->string('status', 16)->default('active');
            $table->uuid('invitation_id')->nullable();
            $table->timestamp('joined_at');
            $table->timestamp('status_changed_at')->nullable();
            $table->foreignUuid('status_changed_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['participant_organization_id', 'status']);
            $table->index(['project_id', 'status']);
        });

        // After the table exists: PostgreSQL needs its primary key first.
        Schema::table('project_participants', function (Blueprint $table) {
            $table->foreign('parent_participant_id')->references('id')->on('project_participants')->restrictOnDelete();
        });

        Schema::create('project_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUuid('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignUuid('inviting_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUuid('inviting_participant_id')->nullable()->constrained('project_participants')->restrictOnDelete();
            $table->foreignUuid('invited_by')->constrained('users');
            $table->foreignUuid('invited_organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->string('email')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('relationship', 24);
            $table->foreignUuid('parent_participant_id')->nullable()->constrained('project_participants')->restrictOnDelete();
            $table->json('capabilities');
            $table->text('message')->nullable();
            $table->string('token_hash', 64)->nullable()->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignUuid('accepted_by')->nullable()->constrained('users');
            $table->foreignUuid('accepted_organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->foreignUuid('participant_id')->nullable()->constrained('project_participants')->restrictOnDelete();
            $table->timestamp('declined_at')->nullable();
            $table->foreignUuid('declined_by')->nullable()->constrained('users');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignUuid('revoked_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['project_id', 'created_at']);
            $table->index(['invited_organization_id', 'accepted_at']);
        });

        Schema::table('project_participants', function (Blueprint $table) {
            $table->foreign('invitation_id')->references('id')->on('project_invitations')->restrictOnDelete();
        });

        Schema::create('project_participant_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('participant_id')->constrained('project_participants')->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained('users');
            $table->json('responsibilities')->nullable();
            $table->foreignUuid('added_by')->constrained('users');
            $table->timestamp('removed_at')->nullable();
            $table->foreignUuid('removed_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['user_id', 'removed_at']);
            $table->index(['participant_id', 'removed_at']);
        });

        Schema::table('project_members', function (Blueprint $table) {
            $table->json('responsibilities')->nullable();
            $table->uuid('org_unit_id')->nullable();
            $table->foreign(['organization_id', 'org_unit_id'])->references(['organization_id', 'id'])->on('org_units');
        });

        Schema::table('org_units', function (Blueprint $table) {
            $table->foreignUuid('manager_person_id')->nullable()->constrained('people')->nullOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            // The project is the owner's, whatever the code does.
            Schema::table('project_participants', fn (Blueprint $table) => TenantSchema::foreign($table, 'project_id', 'projects'));
            Schema::table('project_invitations', fn (Blueprint $table) => TenantSchema::foreign($table, 'project_id', 'projects'));

            DB::statement("ALTER TABLE project_participants ADD CONSTRAINT project_participants_status_check CHECK (status IN ('active', 'suspended', 'completed', 'revoked'))");
            DB::statement("ALTER TABLE project_participants ADD CONSTRAINT project_participants_relationship_check CHECK (relationship IN ('donor', 'lead_implementer', 'implementer', 'sub_implementer', 'auditor', 'other'))");
            DB::statement("ALTER TABLE project_invitations ADD CONSTRAINT project_invitations_relationship_check CHECK (relationship IN ('donor', 'lead_implementer', 'implementer', 'sub_implementer', 'auditor', 'other'))");
            DB::statement('ALTER TABLE project_invitations ADD CONSTRAINT project_invitations_invitee_check CHECK (invited_organization_id IS NOT NULL OR email IS NOT NULL)');
            // One live participation per organization and project; history may repeat.
            DB::statement("CREATE UNIQUE INDEX project_participants_live_unique ON project_participants (project_id, participant_organization_id) WHERE status IN ('active', 'suspended')");
            // One open invitation per organization and project.
            DB::statement('CREATE UNIQUE INDEX project_invitations_pending_org_unique ON project_invitations (project_id, invited_organization_id) WHERE invited_organization_id IS NOT NULL AND accepted_at IS NULL AND declined_at IS NULL AND revoked_at IS NULL');
            // One representative row per person and participation while it stands.
            DB::statement('CREATE UNIQUE INDEX project_participant_members_live_unique ON project_participant_members (participant_id, user_id) WHERE removed_at IS NULL');
        }
    }

    public function down(): void
    {
        Schema::table('org_units', function (Blueprint $table) {
            $table->dropForeign(['manager_person_id']);
            $table->dropColumn('manager_person_id');
        });
        Schema::table('project_members', function (Blueprint $table) {
            $table->dropForeign(['organization_id', 'org_unit_id']);
            $table->dropColumn(['responsibilities', 'org_unit_id']);
        });
        Schema::dropIfExists('project_participant_members');
        Schema::table('project_participants', fn (Blueprint $table) => $table->dropForeign(['invitation_id']));
        Schema::dropIfExists('project_invitations');
        Schema::dropIfExists('project_participants');
    }
};
