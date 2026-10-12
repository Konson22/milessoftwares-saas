<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The organization's medicine catalogue (shared by its branches).
        Schema::create('medicines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->string('name');
            $table->string('generic_name');
            $table->string('form', 32);
            $table->string('strength', 64)->nullable();
            // What one unit of stock is: a tablet, a bottle, a tube.
            $table->string('unit', 32);
            $table->string('barcode', 64)->nullable();
            $table->boolean('prescription_only')->default(false);
            $table->boolean('controlled')->default(false);
            // Money in the currency's minor unit.
            $table->bigInteger('sell_price_minor');
            $table->integer('reorder_level')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'name', 'form', 'strength']);
        });
        DB::statement('CREATE UNIQUE INDEX medicines_organization_barcode_unique ON medicines (organization_id, barcode) WHERE barcode IS NOT NULL');

        // Stock per branch, per batch: what can be sold, and until when.
        Schema::create('batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->uuid('branch_id');
            $table->foreignUuid('medicine_id')->constrained();
            $table->string('batch_number', 64);
            $table->date('expires_on');
            $table->bigInteger('unit_cost_minor');
            $table->integer('quantity_on_hand');
            $table->timestamps();

            $table->unique(['organization_id', 'branch_id', 'medicine_id', 'batch_number']);
            $table->index(['organization_id', 'branch_id', 'medicine_id', 'expires_on']);
        });

        // Every change to stock, append-only: who, why, and what caused it.
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->uuid('branch_id');
            $table->foreignUuid('medicine_id')->constrained();
            $table->foreignUuid('batch_id')->constrained();
            $table->integer('quantity');
            $table->string('type', 32);
            $table->string('reason', 64)->nullable();
            $table->string('note', 255)->nullable();
            $table->string('reference_type', 64)->nullable();
            $table->uuid('reference_id')->nullable();
            $table->foreignUuid('user_id')->constrained('users');
            $table->timestamp('created_at');

            $table->index(['organization_id', 'branch_id', 'medicine_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            // The database itself refuses negative stock and edits to history.
            DB::statement('ALTER TABLE batches ADD CONSTRAINT batches_quantity_not_negative CHECK (quantity_on_hand >= 0)');
            DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_quantity_not_zero CHECK (quantity <> 0)');
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION stock_movements_append_only() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'stock_movements is append-only';
                END;
                $$ LANGUAGE plpgsql
            SQL);
            DB::statement('CREATE TRIGGER stock_movements_append_only BEFORE UPDATE OR DELETE ON stock_movements FOR EACH ROW EXECUTE FUNCTION stock_movements_append_only()');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS stock_movements_append_only ON stock_movements');
            DB::statement('DROP FUNCTION IF EXISTS stock_movements_append_only()');
        }

        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('batches');
        Schema::dropIfExists('medicines');
    }
};
