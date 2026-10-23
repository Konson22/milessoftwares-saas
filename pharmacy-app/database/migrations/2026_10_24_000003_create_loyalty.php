<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Loyalty (App\Modules\Sales\Actions\Loyalty): a program per industry
     * (what spend earns a point, what a point is worth), each customer's
     * points, and every point earned, redeemed or taken back.
     */
    public function up(): void
    {
        Schema::create('loyalty_programs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('application', 32);
            $table->boolean('active')->default(true);
            // Spending this much earns one point.
            $table->unsignedBigInteger('spend_per_point_minor');
            // What one point takes off a sale.
            $table->unsignedBigInteger('point_value_minor');
            $table->uuid('updated_by');
            $table->timestamps();

            $table->unique(['organization_id', 'application']);
        });

        Schema::table('customers', function (Blueprint $table) {
            // Signed: taking back points already spent can leave it below zero.
            $table->bigInteger('loyalty_points')->default(0)->after('payment_terms_days');
        });

        Schema::create('loyalty_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('customer_id');
            $table->uuid('sale_id')->nullable();
            $table->uuid('sale_return_id')->nullable();
            // earned, redeemed, reversed (a void), returned (a return)
            $table->string('kind', 16);
            $table->bigInteger('points');
            $table->timestamp('created_at');

            $table->index(['organization_id', 'customer_id']);
            $table->index(['organization_id', 'sale_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_movements');
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('loyalty_points');
        });
        Schema::dropIfExists('loyalty_programs');
    }
};
