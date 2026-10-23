<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A branch's own price level: the price list its counter sells at
     * (App\Modules\Sales\Actions\ShelfPrices). Null: the shop's own prices.
     */
    public function up(): void
    {
        Schema::table('branch_counter_settings', function (Blueprint $table) {
            $table->uuid('price_list_id')->nullable()->after('manager_closes_shifts');
        });
    }

    public function down(): void
    {
        Schema::table('branch_counter_settings', function (Blueprint $table) {
            $table->dropColumn('price_list_id');
        });
    }
};
