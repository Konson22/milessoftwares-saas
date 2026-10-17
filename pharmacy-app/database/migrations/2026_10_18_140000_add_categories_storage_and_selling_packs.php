<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Items get a category (for finding and reporting) and how they must be
 * stored. Packs can be sold at the counter too: a pack may have
 * its own price (otherwise its units at the unit price), and a sale line
 * sold by the pack keeps the pack's name, size and price as sold. Stock and
 * sale line quantities stay in the item's unit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->string('category', 64)->nullable();
            // room | cool | refrigerated | frozen
            $table->string('storage', 16)->default('room');
            $table->index(['organization_id', 'category']);
        });

        Schema::table('item_packs', function (Blueprint $table) {
            $table->bigInteger('sell_price_minor')->nullable();
        });

        Schema::table('sale_lines', function (Blueprint $table) {
            // Null: sold by the unit.
            $table->string('pack_name', 32)->nullable();
            $table->integer('units_per_pack')->default(1);
            $table->bigInteger('pack_price_minor')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sale_lines', fn (Blueprint $table) => $table->dropColumn(['pack_name', 'units_per_pack', 'pack_price_minor']));
        Schema::table('item_packs', fn (Blueprint $table) => $table->dropColumn('sell_price_minor'));
        Schema::table('items', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'category']);
            $table->dropColumn(['category', 'storage']);
        });
    }
};
