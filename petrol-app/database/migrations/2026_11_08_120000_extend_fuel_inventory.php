<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fuel_products', function (Blueprint $table) {
            $table->string('description')->nullable()->after('code');
            $table->string('unit', 8)->default('L')->after('description');
            $table->unsignedBigInteger('cost_minor')->default(0)->after('unit');
        });

        Schema::create('fuel_costs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('branch_id')->nullable();
            $table->foreignUuid('fuel_product_id')->constrained();
            $table->unsignedBigInteger('cost_minor');
            $table->timestamp('effective_from');
            $table->timestamp('effective_to')->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();
            $table->index(['fuel_product_id', 'branch_id', 'effective_from']);
        });

        Schema::table('stations', function (Blueprint $table) {
            $table->string('inventory_mode', 16)->default('sales')->after('collection_mode');
        });

        Schema::table('tanks', function (Blueprint $table) {
            $table->string('tank_type', 16)->default('underground')->after('code');
            $table->string('material', 64)->nullable()->after('tank_type');
            $table->unsignedBigInteger('safe_fill_milli')->nullable()->after('capacity_milli');
            $table->unsignedBigInteger('reorder_milli')->nullable()->after('low_stock_milli');
            $table->string('calibration_notes')->nullable()->after('status');
            $table->date('inspected_on')->nullable()->after('calibration_notes');
            $table->string('inspection_notes')->nullable()->after('inspected_on');
            $table->date('next_inspection_on')->nullable()->after('inspection_notes');
        });

        Schema::create('tank_calibration_points', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('tank_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('depth_mm');
            $table->unsignedBigInteger('volume_milli');
            $table->timestamps();
            $table->unique(['tank_id', 'depth_mm']);
        });

        Schema::table('tank_measurements', function (Blueprint $table) {
            $table->string('method', 16)->default('volume')->after('shift_id');
            $table->unsignedInteger('depth_mm')->nullable()->after('method');
            $table->decimal('temperature_c', 5, 2)->nullable()->after('depth_mm');
            $table->unsignedInteger('water_mm')->nullable()->after('temperature_c');
            $table->unsignedBigInteger('book_milli')->default(0)->after('measured_milli');
            $table->string('status', 16)->default('recorded')->after('variance_milli');
            $table->foreignUuid('verified_by')->nullable()->after('measured_by')->constrained('users');
            $table->timestamp('verified_at')->nullable()->after('verified_by');
            $table->foreignUuid('approved_by')->nullable()->after('verified_at')->constrained('users');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->string('idempotency_key', 64)->nullable()->after('notes');
            $table->unique(['organization_id', 'idempotency_key']);
        });

        Schema::table('fuel_receipts', function (Blueprint $table) {
            $table->string('status', 16)->default('pending')->after('discrepancy_milli');
            $table->unsignedBigInteger('accepted_milli')->nullable()->after('status');
            $table->foreignUuid('approved_by')->nullable()->after('received_by')->constrained('users');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
        });

        Schema::create('fuel_transfers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->string('number', 32);
            $table->foreignUuid('source_tank_id')->constrained('tanks');
            $table->foreignUuid('destination_tank_id')->constrained('tanks');
            $table->foreignUuid('fuel_product_id')->constrained();
            $table->unsignedBigInteger('dispatched_milli');
            $table->unsignedBigInteger('received_milli');
            $table->bigInteger('discrepancy_milli');
            $table->string('status', 16);
            $table->string('notes')->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamp('transferred_at');
            $table->foreignUuid('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'number']);
            $table->unique(['organization_id', 'idempotency_key']);
        });

        Schema::create('fuel_draws', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->foreignUuid('tank_id')->constrained();
            $table->foreignUuid('nozzle_id')->nullable()->constrained();
            $table->foreignUuid('shift_id')->nullable()->constrained();
            $table->unsignedBigInteger('quantity_milli');
            $table->string('purpose', 16);
            $table->string('notes')->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->unique(['organization_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fuel_draws');
        Schema::dropIfExists('fuel_transfers');
        Schema::dropIfExists('tank_calibration_points');
        Schema::dropIfExists('fuel_costs');

        Schema::table('fuel_receipts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['status', 'accepted_milli', 'approved_at']);
        });

        Schema::table('tank_measurements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('verified_by');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropUnique(['organization_id', 'idempotency_key']);
            $table->dropColumn(['method', 'depth_mm', 'temperature_c', 'water_mm', 'book_milli', 'status', 'verified_at', 'approved_at', 'idempotency_key']);
        });

        Schema::table('tanks', function (Blueprint $table) {
            $table->dropColumn(['tank_type', 'material', 'safe_fill_milli', 'reorder_milli', 'calibration_notes', 'inspected_on', 'inspection_notes', 'next_inspection_on']);
        });

        Schema::table('stations', function (Blueprint $table) {
            $table->dropColumn('inventory_mode');
        });

        Schema::table('fuel_products', function (Blueprint $table) {
            $table->dropColumn(['description', 'unit', 'cost_minor']);
        });
    }
};
