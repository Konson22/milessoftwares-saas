<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Single-use, expiring invitations. Only the token hash is stored. The
        // proposed access is re-validated against the inviter's delegation
        // authority when the invitation is accepted.
        Schema::create('invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('token_hash', 64)->unique();
            $table->uuid('invited_by_membership_id');
            $table->jsonb('proposed_access')->nullable();
            $table->string('status', 16)->default('pending');
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->foreignUuid('accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->index(['organization_id', 'status']);
            $table->foreign(['organization_id', 'invited_by_membership_id'])->references(['organization_id', 'id'])->on('organization_memberships');
        });

        DB::statement("ALTER TABLE invitations ADD CONSTRAINT invitations_status_check CHECK (status IN ('pending', 'accepted', 'revoked', 'expired'))");
        DB::statement("CREATE UNIQUE INDEX invitations_one_pending_per_email ON invitations (organization_id, lower(email)) WHERE status = 'pending'");

        Schema::create('provisioning_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key', 128)->unique();
            $table->string('status', 16)->default('pending');
            $table->foreignUuid('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'id']);
        });

        DB::statement("ALTER TABLE provisioning_runs ADD CONSTRAINT provisioning_runs_status_check CHECK (status IN ('pending', 'running', 'succeeded', 'failed', 'cancelled'))");

        Schema::create('provisioning_steps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('provisioning_run_id');
            $table->foreignUuid('application_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('step_key', 64);
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['provisioning_run_id', 'step_key']);
            $table->foreign(['organization_id', 'provisioning_run_id'])->references(['organization_id', 'id'])->on('provisioning_runs')->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE provisioning_steps ADD CONSTRAINT provisioning_steps_status_check CHECK (status IN ('pending', 'running', 'succeeded', 'failed', 'skipped'))");

        foreach (['demo_requests', 'contact_messages'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name) {
                $table->uuid('id')->primary();
                $table->string('name');
                $table->string('email');
                $table->string('company')->nullable();
                $table->string('phone', 32)->nullable();
                $table->char('country_code', 2)->nullable();
                if ($name === 'demo_requests') {
                    $table->foreignUuid('application_id')->nullable()->constrained()->nullOnDelete();
                    $table->string('preferred_contact_time')->nullable();
                } else {
                    $table->string('subject')->nullable();
                }
                $table->text('message')->nullable();
                $table->string('status', 16)->default('new');
                $table->ipAddress('ip_address')->nullable();
                $table->text('user_agent')->nullable();
                $table->timestampsTz();

                $table->index(['status', 'created_at']);
            });

            DB::statement("ALTER TABLE {$name} ADD CONSTRAINT {$name}_status_check CHECK (status IN ('new', 'in_progress', 'closed', 'spam'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contact_messages');
        Schema::dropIfExists('demo_requests');
        Schema::dropIfExists('provisioning_steps');
        Schema::dropIfExists('provisioning_runs');
        Schema::dropIfExists('invitations');
    }
};
