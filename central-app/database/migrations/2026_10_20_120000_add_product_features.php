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
     * Products declare their plan features in their catalog, versioned like
     * permissions, so plans and grants can only name features that exist.
     * Staff overrides stay entitlement rows: a manual row is a grant or an
     * explicit denial, with the reason and who made it, and is never touched
     * by plan changes.
     */
    public function up(): void
    {
        Schema::create('application_features', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('application_id');
            $table->uuid('catalog_version_id');
            $table->string('key', 100);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 16)->default('available');
            // What a limit counts ("users", "branches"); NULL = on or off only.
            $table->string('limit_unit', 32)->nullable();
            $table->timestampsTz();

            $table->unique(['catalog_version_id', 'key']);
            $table->foreign(['application_id', 'catalog_version_id'])->references(['application_id', 'id'])->on('application_catalog_versions')->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE application_features ADD CONSTRAINT application_features_status_check CHECK (status IN ('available', 'beta', 'deprecated'))");

        Schema::table('entitlements', function (Blueprint $table) {
            $table->string('effect', 8)->default('grant');
            $table->text('reason')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
        });

        DB::statement("ALTER TABLE entitlements ADD CONSTRAINT entitlements_effect_check CHECK (effect IN ('grant', 'deny'))");
        // Only staff deny, and only features: application access ends by revoking.
        DB::statement("ALTER TABLE entitlements ADD CONSTRAINT entitlements_denial_check CHECK (effect = 'grant' OR (source = 'manual' AND feature_key IS NOT NULL))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE entitlements DROP CONSTRAINT entitlements_denial_check');
        DB::statement('ALTER TABLE entitlements DROP CONSTRAINT entitlements_effect_check');

        Schema::table('entitlements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['effect', 'reason']);
        });

        Schema::dropIfExists('application_features');
    }
};
