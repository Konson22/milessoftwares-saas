<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Serial numbers (App\Modules\Sales\Actions\Serials): items that
     * track them are sold one recorded serial per unit, and each serial
     * keeps the sale it left in, for warranty and returns.
     */
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->boolean('tracks_serials')->default(false)->after('tracks_expiry');
        });

        Schema::create('item_serials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('item_id');
            $table->string('serial', 64);
            // in_stock, sold
            $table->string('status', 16);
            $table->uuid('sale_id')->nullable();
            $table->uuid('recorded_by');
            $table->timestamp('recorded_at');
            $table->timestamp('sold_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'item_id', 'serial']);
            $table->index(['organization_id', 'serial']);
            $table->index(['organization_id', 'sale_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_serials');
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('tracks_serials');
        });
    }
};
