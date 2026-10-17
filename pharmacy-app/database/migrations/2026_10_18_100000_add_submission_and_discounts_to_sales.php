<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The counter sends a key with every sale it submits: sent twice (a double
 * click, a retry on a slow line), the second is the first sale again.
 * Discounts are given per line and kept next to the price.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->uuid('submission_id')->nullable();
            $table->bigInteger('discount_minor')->default(0);

            $table->unique(['organization_id', 'submission_id']);
        });

        Schema::table('sale_lines', function (Blueprint $table) {
            $table->bigInteger('discount_minor')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('sale_lines', function (Blueprint $table) {
            $table->dropColumn('discount_minor');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'submission_id']);
            $table->dropColumn(['submission_id', 'discount_minor']);
        });
    }
};
