<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * At a direct-sale counter: paid now, handed over unpaid (paid later at
     * the cashier), or the seller chooses per sale.
     */
    public function up(): void
    {
        Schema::table('branch_counter_settings', function (Blueprint $table) {
            $table->string('direct_payment', 16)->default('paid')->after('workflow');
        });
    }

    public function down(): void
    {
        Schema::table('branch_counter_settings', function (Blueprint $table) {
            $table->dropColumn('direct_payment');
        });
    }
};
