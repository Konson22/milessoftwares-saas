<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The ERP product a plan sells, if any. Nullable and additive: existing
     * plans keep selling applications as before.
     */
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->foreignUuid('erp_product_id')->nullable()->after('id')->constrained('erp_products')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('erp_product_id');
        });
    }
};
