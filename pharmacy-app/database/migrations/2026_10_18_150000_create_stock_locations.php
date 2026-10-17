<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where stock is kept within a branch (a shelf, the store room, the fridge).
 * A batch's units can be spread over locations (batch_locations); what is
 * not placed anywhere is "not put away". Moving units between locations
 * never changes what is on hand, and is logged in stock_location_moves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->uuid('branch_id');
            $table->string('name', 64);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'branch_id', 'name']);
        });

        Schema::create('batch_locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->foreignUuid('batch_id')->constrained();
            $table->foreignUuid('stock_location_id')->constrained();
            $table->integer('quantity');
            $table->timestamps();

            $table->unique(['batch_id', 'stock_location_id']);
            $table->index(['stock_location_id']);
        });

        Schema::create('stock_location_moves', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->uuid('branch_id');
            $table->foreignUuid('batch_id')->constrained();
            // Null: not put away.
            $table->foreignUuid('from_location_id')->nullable()->constrained('stock_locations');
            $table->foreignUuid('to_location_id')->nullable()->constrained('stock_locations');
            $table->integer('quantity');
            $table->foreignUuid('user_id')->constrained('users');
            $table->timestamp('created_at');

            $table->index(['organization_id', 'branch_id', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE batch_locations ADD CONSTRAINT batch_locations_quantity_not_negative CHECK (quantity >= 0)');
            DB::statement('ALTER TABLE stock_location_moves ADD CONSTRAINT stock_location_moves_quantity_positive CHECK (quantity > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_location_moves');
        Schema::dropIfExists('batch_locations');
        Schema::dropIfExists('stock_locations');
    }
};
