<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_numbers', function (Blueprint $table) {
            $table->uuid('organization_id');
            $table->string('type', 32);
            $table->unsignedBigInteger('last_number');
            $table->primary(['organization_id', 'type']);
        });

        Schema::create('stations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->string('code', 32);
            $table->string('collection_mode', 16);
            $table->unsignedTinyInteger('low_stock_percent')->default(15);
            $table->string('hours')->nullable();
            $table->string('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'branch_id']);
            $table->unique(['organization_id', 'code']);
        });

        Schema::create('shift_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->string('name', 64);
            $table->string('starts_at', 5);
            $table->string('ends_at', 5);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['branch_id', 'name']);
        });

        Schema::create('payment_methods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->string('code', 32);
            $table->string('name');
            $table->string('kind', 32);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['branch_id', 'code']);
        });

        Schema::create('fuel_products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('name');
            $table->string('code', 32);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
        });

        Schema::create('fuel_prices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->foreignUuid('fuel_product_id')->constrained();
            $table->unsignedBigInteger('selling_price_minor');
            $table->timestamp('effective_from');
            $table->timestamp('effective_to')->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();
            $table->index(['branch_id', 'fuel_product_id', 'effective_from']);
        });

        Schema::create('tanks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->foreignUuid('fuel_product_id')->constrained();
            $table->string('name');
            $table->string('code', 32);
            $table->unsignedBigInteger('capacity_milli');
            $table->bigInteger('quantity_milli')->default(0);
            $table->unsignedBigInteger('average_cost_minor')->default(0);
            $table->unsignedBigInteger('low_stock_milli')->nullable();
            $table->string('status', 16);
            $table->timestamps();
            $table->unique(['branch_id', 'code']);
        });

        Schema::create('pumps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->string('name');
            $table->string('code', 32);
            $table->string('status', 32);
            $table->timestamps();
            $table->unique(['branch_id', 'code']);
        });

        Schema::create('nozzles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->foreignUuid('pump_id')->constrained();
            $table->foreignUuid('fuel_product_id')->constrained();
            $table->string('name');
            $table->string('code', 32);
            $table->string('status', 32);
            $table->timestamps();
            $table->unique(['pump_id', 'code']);
        });

        Schema::create('nozzle_tank_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('nozzle_id')->constrained();
            $table->foreignUuid('tank_id')->constrained();
            $table->timestamp('connected_at');
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamps();
        });

        Schema::create('attendants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('user_id')->nullable()->unique()->constrained('users');
            $table->string('name');
            $table->string('phone', 32)->nullable();
            $table->string('employee_code', 32);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'employee_code']);
        });

        Schema::create('attendant_stations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('attendant_id')->constrained();
            $table->uuid('branch_id');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['attendant_id', 'branch_id']);
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('kind', 32);
            $table->string('name');
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('tax_id', 64)->nullable();
            $table->unsignedBigInteger('credit_limit_minor')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('name');
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('shifts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->foreignUuid('schedule_id')->nullable()->constrained('shift_schedules');
            $table->string('number', 32);
            $table->date('business_date');
            $table->string('status', 32);
            $table->unsignedBigInteger('opening_float_minor');
            $table->foreignUuid('opened_by')->constrained('users');
            $table->timestamp('opened_at');
            $table->foreignUuid('submitted_by')->nullable()->constrained('users');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignUuid('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->foreignUuid('rejected_by')->nullable()->constrained('users');
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->unsignedBigInteger('counted_drawer_minor')->nullable();
            $table->bigInteger('expected_cash_minor')->nullable();
            $table->bigInteger('cash_difference_minor')->nullable();
            $table->string('closing_note')->nullable();
            $table->string('handover_note')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'number']);
            $table->index(['branch_id', 'status']);
        });

        Schema::create('shift_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('shift_id')->constrained();
            $table->foreignUuid('attendant_id')->constrained();
            $table->foreignUuid('nozzle_id')->constrained();
            $table->unsignedBigInteger('counted_cash_minor')->nullable();
            $table->timestamps();
            $table->unique(['shift_id', 'nozzle_id']);
        });

        Schema::create('meter_readings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->foreignUuid('shift_id')->constrained();
            $table->foreignUuid('nozzle_id')->constrained();
            $table->string('kind', 16);
            $table->unsignedBigInteger('reading_milli');
            $table->foreignUuid('recorded_by')->constrained('users');
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->unique(['shift_id', 'nozzle_id', 'kind']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->foreignUuid('tank_id')->constrained();
            $table->string('kind', 32);
            $table->bigInteger('quantity_milli');
            $table->unsignedBigInteger('unit_cost_minor')->default(0);
            $table->string('reference_type')->nullable();
            $table->uuid('reference_id')->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->string('notes')->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->unique(['organization_id', 'idempotency_key']);
            $table->index(['tank_id', 'occurred_at']);
        });

        Schema::create('tank_measurements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->foreignUuid('tank_id')->constrained();
            $table->foreignUuid('shift_id')->nullable()->constrained();
            $table->unsignedBigInteger('measured_milli');
            $table->bigInteger('variance_milli');
            $table->foreignUuid('measured_by')->constrained('users');
            $table->timestamp('measured_at');
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('fuel_sales', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->foreignUuid('shift_id')->constrained();
            $table->foreignUuid('nozzle_id')->constrained();
            $table->foreignUuid('attendant_id')->constrained();
            $table->foreignUuid('fuel_product_id')->constrained();
            $table->foreignUuid('tank_id')->constrained();
            $table->string('number', 32);
            $table->unsignedBigInteger('quantity_milli');
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedBigInteger('total_minor');
            $table->unsignedBigInteger('cost_minor');
            $table->string('collected_by', 16);
            $table->string('status', 16);
            $table->string('idempotency_key', 64)->nullable();
            $table->foreignUuid('customer_id')->nullable()->constrained();
            $table->foreignUuid('sold_by')->constrained('users');
            $table->timestamp('sold_at');
            $table->foreignUuid('voided_by')->nullable()->constrained('users');
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'number']);
            $table->unique(['organization_id', 'idempotency_key']);
            $table->index(['branch_id', 'sold_at']);
            $table->index(['shift_id', 'status']);
        });

        Schema::create('sale_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('fuel_sale_id')->constrained();
            $table->string('kind', 32);
            $table->unsignedBigInteger('amount_minor');
            $table->string('reference')->nullable();
            $table->foreignUuid('customer_id')->nullable()->constrained();
            $table->timestamps();
        });

        Schema::create('credit_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('customer_id')->constrained();
            $table->uuid('branch_id');
            $table->foreignUuid('fuel_sale_id')->nullable()->constrained();
            $table->string('kind', 32);
            $table->bigInteger('amount_minor');
            $table->string('idempotency_key', 64)->nullable();
            $table->string('note')->nullable();
            $table->foreignUuid('recorded_by')->constrained('users');
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->unique(['organization_id', 'idempotency_key']);
            $table->index(['customer_id', 'occurred_at']);
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->foreignUuid('supplier_id')->constrained();
            $table->string('number', 32);
            $table->string('status', 16);
            $table->timestamp('ordered_at');
            $table->date('expected_on')->nullable();
            $table->string('notes')->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();
            $table->unique(['organization_id', 'number']);
        });

        Schema::create('purchase_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('purchase_order_id')->constrained();
            $table->foreignUuid('fuel_product_id')->constrained();
            $table->unsignedBigInteger('quantity_milli');
            $table->unsignedBigInteger('received_milli')->default(0);
            $table->unsignedBigInteger('unit_cost_minor');
            $table->timestamps();
        });

        Schema::create('fuel_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->foreignUuid('supplier_id')->constrained();
            $table->foreignUuid('purchase_order_id')->nullable()->constrained();
            $table->foreignUuid('purchase_line_id')->nullable()->constrained('purchase_lines');
            $table->foreignUuid('tank_id')->constrained();
            $table->string('number', 32);
            $table->string('tanker', 64)->nullable();
            $table->string('driver')->nullable();
            $table->unsignedBigInteger('declared_milli');
            $table->unsignedBigInteger('measured_milli');
            $table->bigInteger('discrepancy_milli');
            $table->unsignedBigInteger('unit_cost_minor');
            $table->unsignedBigInteger('total_minor');
            $table->string('supplier_reference')->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->foreignUuid('received_by')->constrained('users');
            $table->timestamp('received_at');
            $table->timestamps();
            $table->unique(['organization_id', 'number']);
            $table->unique(['organization_id', 'idempotency_key']);
        });

        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->foreignUuid('supplier_id')->constrained();
            $table->foreignUuid('fuel_receipt_id')->nullable()->constrained();
            $table->unsignedBigInteger('amount_minor');
            $table->string('paid_from', 16);
            $table->string('reference')->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->foreignUuid('paid_by')->constrained('users');
            $table->timestamp('paid_at');
            $table->timestamps();
            $table->unique(['organization_id', 'idempotency_key']);
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->foreignUuid('shift_id')->nullable()->constrained();
            $table->string('category', 64);
            $table->string('description');
            $table->unsignedBigInteger('amount_minor');
            $table->string('paid_from', 16);
            $table->date('incurred_on');
            $table->string('idempotency_key', 64)->nullable();
            $table->foreignUuid('recorded_by')->constrained('users');
            $table->timestamps();
            $table->unique(['organization_id', 'idempotency_key']);
        });

        Schema::create('cash_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->foreignUuid('shift_id')->nullable()->constrained();
            $table->string('kind', 32);
            $table->bigInteger('amount_minor');
            $table->foreignUuid('fuel_sale_id')->nullable()->constrained();
            $table->foreignUuid('expense_id')->nullable()->constrained();
            $table->foreignUuid('supplier_payment_id')->nullable()->constrained();
            $table->foreignUuid('credit_entry_id')->nullable()->constrained();
            $table->string('idempotency_key', 64)->nullable();
            $table->string('note')->nullable();
            $table->foreignUuid('recorded_by')->constrained('users');
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->unique(['organization_id', 'idempotency_key']);
            $table->index(['shift_id', 'kind']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('branch_id')->nullable();
            $table->foreignUuid('actor_id')->nullable()->constrained('users');
            $table->string('action', 64);
            $table->string('subject_type');
            $table->uuid('subject_id');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at');
            $table->index(['organization_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('app_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('kind', 32);
            $table->json('result');
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('created_at');
            $table->index(['organization_id', 'created_at']);
        });

        // One open shift per station, and one current tank per nozzle.
        DB::statement("create unique index shifts_one_open_per_station on shifts (organization_id, branch_id) where status = 'open'");
        DB::statement('create unique index nozzle_current_tank on nozzle_tank_connections (nozzle_id) where disconnected_at is null');
    }

    public function down(): void
    {
        Schema::dropIfExists('app_submissions');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('supplier_payments');
        Schema::dropIfExists('fuel_receipts');
        Schema::dropIfExists('purchase_lines');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('credit_entries');
        Schema::dropIfExists('sale_payments');
        Schema::dropIfExists('fuel_sales');
        Schema::dropIfExists('tank_measurements');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('meter_readings');
        Schema::dropIfExists('shift_assignments');
        Schema::dropIfExists('shifts');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('attendant_stations');
        Schema::dropIfExists('attendants');
        Schema::dropIfExists('nozzle_tank_connections');
        Schema::dropIfExists('nozzles');
        Schema::dropIfExists('pumps');
        Schema::dropIfExists('tanks');
        Schema::dropIfExists('fuel_prices');
        Schema::dropIfExists('fuel_products');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('shift_schedules');
        Schema::dropIfExists('stations');
        Schema::dropIfExists('document_numbers');
    }
};
