<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The shared item master (ERP Phase 3.2). Stock, purchasing and sales point
 * at an Inventory item instead of a Pharmacy medicine; a medicine keeps only
 * what is pharmacy's own (name, form, strength) and shares its id with its
 * item. Every existing medicine becomes an item with the same id, so no row
 * that points at one changes its value.
 */
return new class extends Migration
{
    /**
     * Tables whose medicine_id becomes item_id.
     */
    private const LINES = ['batches', 'stock_movements', 'purchase_order_lines', 'goods_receipt_lines', 'sale_lines', 'supplier_return_lines'];

    public function up(): void
    {
        // Anything the organization stocks, buys and sells, whatever the industry.
        Schema::create('items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            // Which module keeps the item's own details: "medicine" (Pharmacy).
            $table->string('kind', 32);
            // As it reads on documents: "Amoxicillin 500 mg capsule".
            $table->string('name');
            // Another name it is looked up by (a medicine's generic name).
            $table->string('alias')->nullable();
            // What one unit of stock is: a tablet, a bottle, a tube.
            $table->string('unit', 32);
            $table->string('barcode', 64)->nullable();
            // Money in the currency's minor unit.
            $table->bigInteger('sell_price_minor');
            $table->integer('reorder_level')->default(0);
            // Sold only with an authorisation (a prescription).
            $table->boolean('restricted')->default(false);
            // Every movement is kept in a register (controlled drugs).
            $table->boolean('controlled')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['organization_id', 'kind']);
        });
        DB::statement('CREATE UNIQUE INDEX items_organization_barcode_unique ON items (organization_id, barcode) WHERE barcode IS NOT NULL');

        DB::statement(<<<'SQL'
            INSERT INTO items (id, organization_id, kind, name, alias, unit, barcode, sell_price_minor, reorder_level, restricted, controlled, active, created_at, updated_at)
            SELECT id, organization_id, 'medicine', TRIM(name || ' ' || COALESCE(strength, '') || ' ' || form), generic_name, unit, barcode, sell_price_minor, reorder_level, prescription_only, controlled, active, created_at, updated_at
            FROM medicines
        SQL);

        $this->dropStockIndexes('medicine_id');
        foreach (self::LINES as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropForeign(['medicine_id']));
            Schema::table($name, fn (Blueprint $table) => $table->renameColumn('medicine_id', 'item_id'));
            Schema::table($name, fn (Blueprint $table) => $table->foreign('item_id')->references('id')->on('items'));
        }
        $this->addStockIndexes('item_id');

        DB::statement('DROP INDEX medicines_organization_barcode_unique');
        Schema::table('medicines', function (Blueprint $table) {
            $table->dropColumn(['generic_name', 'unit', 'barcode', 'prescription_only', 'controlled', 'sell_price_minor', 'reorder_level', 'active']);
        });
        Schema::table('medicines', fn (Blueprint $table) => $table->foreign('id')->references('id')->on('items'));
    }

    public function down(): void
    {
        Schema::table('medicines', fn (Blueprint $table) => $table->dropForeign(['id']));
        Schema::table('medicines', function (Blueprint $table) {
            $table->string('generic_name')->default('');
            $table->string('unit', 32)->default('');
            $table->string('barcode', 64)->nullable();
            $table->boolean('prescription_only')->default(false);
            $table->boolean('controlled')->default(false);
            $table->bigInteger('sell_price_minor')->default(0);
            $table->integer('reorder_level')->default(0);
            $table->boolean('active')->default(true);
        });
        DB::statement(<<<'SQL'
            UPDATE medicines SET
                generic_name = COALESCE((SELECT alias FROM items WHERE items.id = medicines.id), ''),
                unit = (SELECT unit FROM items WHERE items.id = medicines.id),
                barcode = (SELECT barcode FROM items WHERE items.id = medicines.id),
                prescription_only = (SELECT restricted FROM items WHERE items.id = medicines.id),
                controlled = (SELECT controlled FROM items WHERE items.id = medicines.id),
                sell_price_minor = (SELECT sell_price_minor FROM items WHERE items.id = medicines.id),
                reorder_level = (SELECT reorder_level FROM items WHERE items.id = medicines.id),
                active = (SELECT active FROM items WHERE items.id = medicines.id)
        SQL);
        DB::statement('CREATE UNIQUE INDEX medicines_organization_barcode_unique ON medicines (organization_id, barcode) WHERE barcode IS NOT NULL');

        // Only items that are medicines can go back; anything else stops the rollback here.
        $this->dropStockIndexes('item_id');
        foreach (self::LINES as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropForeign(['item_id']));
            Schema::table($name, fn (Blueprint $table) => $table->renameColumn('item_id', 'medicine_id'));
            Schema::table($name, fn (Blueprint $table) => $table->foreign('medicine_id')->references('id')->on('medicines'));
        }
        $this->addStockIndexes('medicine_id');

        Schema::drop('items');
    }

    private function dropStockIndexes(string $column): void
    {
        Schema::table('batches', function (Blueprint $table) use ($column) {
            $table->dropUnique(['organization_id', 'branch_id', $column, 'batch_number']);
            $table->dropIndex(['organization_id', 'branch_id', $column, 'expires_on']);
        });
        Schema::table('stock_movements', fn (Blueprint $table) => $table->dropIndex(['organization_id', 'branch_id', $column, 'created_at']));
    }

    private function addStockIndexes(string $column): void
    {
        Schema::table('batches', function (Blueprint $table) use ($column) {
            $table->unique(['organization_id', 'branch_id', $column, 'batch_number']);
            $table->index(['organization_id', 'branch_id', $column, 'expires_on']);
        });
        Schema::table('stock_movements', fn (Blueprint $table) => $table->index(['organization_id', 'branch_id', $column, 'created_at']));
    }
};
