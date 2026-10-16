<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Commercial ERP products (Miles Pharmacy ERP, ...) as opposed to the
     * technical applications in the registry. A product is a named set of
     * applications; it owns no entitlements of its own. Whether an
     * organization has a product is derived from its application
     * entitlements, so the two can never disagree.
     *
     * Additive only: no existing table changes.
     */
    public function up(): void
    {
        Schema::create('erp_products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->string('tagline')->nullable();
            $table->text('description')->nullable();
            // planned = coming soon, pilot = selected customers, available = generally sold.
            $table->string('status', 16)->default('planned');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE erp_products ADD CONSTRAINT erp_products_code_check CHECK (code ~ '^[a-z][a-z0-9_]*$')");
        DB::statement("ALTER TABLE erp_products ADD CONSTRAINT erp_products_status_check CHECK (status IN ('planned', 'pilot', 'available', 'retired'))");

        Schema::create('erp_product_applications', function (Blueprint $table) {
            $table->foreignUuid('erp_product_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('application_id')->constrained()->restrictOnDelete();
            // required: the product does not work without it; optional: an add-on.
            $table->string('requirement', 16);

            $table->primary(['erp_product_id', 'application_id']);
            $table->index('application_id');
        });

        DB::statement("ALTER TABLE erp_product_applications ADD CONSTRAINT erp_product_applications_requirement_check CHECK (requirement IN ('required', 'optional'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_product_applications');
        Schema::dropIfExists('erp_products');
    }
};
