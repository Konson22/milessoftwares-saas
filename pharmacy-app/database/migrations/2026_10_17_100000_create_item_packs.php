<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Units and packaging (ERP Phase 3.3). Stock is always counted in the
 * item's unit (a tablet); an item can also be bought in packs (a box of 30).
 * Order and receipt lines keep what the supplier sold - so many packs at so
 * much a pack - with the pack's name and size copied onto the line, so
 * later changes to the pack never rewrite a document. Existing lines are
 * single units (units_per_pack 1) and read exactly as before.
 */
return new class extends Migration
{
    private const LINES = ['purchase_order_lines', 'goods_receipt_lines'];

    public function up(): void
    {
        Schema::create('item_packs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->foreignUuid('item_id')->constrained();
            // "box", "strip", "carton"
            $table->string('name', 32);
            // How many of the item's unit one pack holds.
            $table->integer('units');
            $table->timestamps();

            $table->unique(['item_id', 'name']);
        });

        foreach (self::LINES as $name) {
            Schema::table($name, function (Blueprint $table) {
                // Null: bought by the unit. Quantity and cost are per pack otherwise.
                $table->string('pack_name', 32)->nullable();
                $table->integer('units_per_pack')->default(1);
            });
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE item_packs ADD CONSTRAINT item_packs_units_more_than_one CHECK (units > 1)');
            foreach (self::LINES as $name) {
                DB::statement("ALTER TABLE {$name} ADD CONSTRAINT {$name}_units_per_pack_positive CHECK (units_per_pack >= 1)");
            }
        }
    }

    public function down(): void
    {
        foreach (self::LINES as $name) {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("ALTER TABLE {$name} DROP CONSTRAINT IF EXISTS {$name}_units_per_pack_positive");
            }
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn(['pack_name', 'units_per_pack']));
        }

        Schema::dropIfExists('item_packs');
    }
};
