<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Commercial SaaS billing for Miles Global Softwares itself. This is not the
     * customers' own accounting, which belongs to the Finance application.
     */
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('billing_interval', 16);
            $table->unsignedBigInteger('price_minor')->default(0);
            $table->char('currency', 3);
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->boolean('is_bundle')->default(false);
            $table->boolean('is_public')->default(true);
            $table->string('status', 16)->default('draft');
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE plans ADD CONSTRAINT plans_billing_interval_check CHECK (billing_interval IN ('monthly', 'yearly', 'custom'))");
        DB::statement("ALTER TABLE plans ADD CONSTRAINT plans_status_check CHECK (status IN ('draft', 'active', 'retired'))");

        // Applications a plan covers: one row for a single-product plan, several
        // for a bundle.
        Schema::create('plan_applications', function (Blueprint $table) {
            $table->foreignUuid('plan_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('application_id')->constrained()->restrictOnDelete();

            $table->primary(['plan_id', 'application_id']);
        });

        Schema::create('plan_features', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('plan_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('application_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('feature_key', 100);
            // NULL limit = feature enabled without a numeric cap.
            $table->bigInteger('limit_value')->nullable();
            $table->timestampsTz();

            $table->unique(['plan_id', 'feature_key']);
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16);
            $table->string('source', 16)->default('manual');
            $table->timestampTz('trial_ends_at')->nullable();
            $table->timestampTz('current_period_start')->nullable();
            $table->timestampTz('current_period_end')->nullable();
            $table->timestampTz('renews_at')->nullable();
            $table->timestampTz('suspended_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('expired_at')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'status']);
        });

        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_status_check CHECK (status IN ('trialing', 'active', 'past_due', 'suspended', 'expired', 'cancelled'))");
        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_source_check CHECK (source IN ('manual', 'online'))");

        Schema::create('subscription_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('subscription_id');
            $table->foreignUuid('plan_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->string('status', 16)->default('active');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'id']);
            $table->foreign(['organization_id', 'subscription_id'])->references(['organization_id', 'id'])->on('subscriptions')->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE subscription_items ADD CONSTRAINT subscription_items_status_check CHECK (status IN ('active', 'ended'))");

        // Effective rights derived from subscriptions or granted manually. Access
        // decisions read this table, never subscriptions directly.
        Schema::create('entitlements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('application_id')->constrained()->restrictOnDelete();
            // NULL feature_key = access to the application itself.
            $table->string('feature_key', 100)->nullable();
            $table->bigInteger('limit_value')->nullable();
            $table->string('source', 16);
            $table->uuid('subscription_item_id')->nullable();
            $table->timestampTz('valid_from');
            $table->timestampTz('valid_until')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->index(['organization_id', 'application_id']);
            $table->foreign(['organization_id', 'subscription_item_id'])->references(['organization_id', 'id'])->on('subscription_items')->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE entitlements ADD CONSTRAINT entitlements_source_check CHECK (source IN ('subscription', 'trial', 'manual'))");
        DB::statement('ALTER TABLE entitlements ADD CONSTRAINT entitlements_validity_check CHECK (valid_until IS NULL OR valid_until > valid_from)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('entitlements');
        Schema::dropIfExists('subscription_items');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plan_features');
        Schema::dropIfExists('plan_applications');
        Schema::dropIfExists('plans');
    }
};
