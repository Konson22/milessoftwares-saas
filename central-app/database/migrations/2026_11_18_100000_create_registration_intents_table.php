<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a visitor chose on the public site (an application, an ERP
     * product such as NGO, or a plan) while they register, verify their
     * address, sign in and pick the organization it is for. The token is a
     * bearer credential carried in links, so only its hash is kept. It
     * holds no personal data beyond the account that claimed it.
     */
    public function up(): void
    {
        Schema::create('registration_intents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->char('token_hash', 64)->unique();
            $table->string('target_type', 16);
            $table->string('target_code', 64);
            $table->string('plan_code', 64)->nullable();
            $table->string('action', 16);
            $table->string('source', 120)->nullable();
            $table->jsonb('campaign')->nullable();
            $table->foreignUuid('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestampTz('claimed_at')->nullable();
            $table->foreignUuid('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 16)->default('pending');
            $table->string('outcome', 32)->nullable();
            $table->jsonb('outcome_details')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'status']);
            $table->index('expires_at');
        });

        DB::statement("ALTER TABLE registration_intents ADD CONSTRAINT registration_intents_target_check CHECK (target_type IN ('application', 'product', 'plan'))");
        DB::statement("ALTER TABLE registration_intents ADD CONSTRAINT registration_intents_action_check CHECK (action IN ('trial', 'subscribe'))");
        DB::statement("ALTER TABLE registration_intents ADD CONSTRAINT registration_intents_status_check CHECK (status IN ('pending', 'completed', 'cancelled'))");
        // Completed means for one organization.
        DB::statement("ALTER TABLE registration_intents ADD CONSTRAINT registration_intents_completed_check CHECK (status <> 'completed' OR (organization_id IS NOT NULL AND user_id IS NOT NULL AND completed_at IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_intents');
    }
};
