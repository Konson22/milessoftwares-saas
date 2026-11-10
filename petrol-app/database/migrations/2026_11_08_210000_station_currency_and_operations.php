<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->json('operations')->nullable();
        });

        Schema::table('fuel_prices', function (Blueprint $table) {
            $table->char('currency', 3)->nullable();
        });

        Schema::table('fuel_sales', function (Blueprint $table) {
            $table->char('currency', 3)->nullable();
            $table->char('fx_base', 3)->nullable();
            $table->char('fx_quote', 3)->nullable();
            $table->unsignedBigInteger('fx_rate_minor')->nullable();
            $table->bigInteger('reporting_total_minor')->nullable();
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->uuid('category_id')->nullable();
            $table->uuid('schedule_id')->nullable();
            $table->char('currency', 3)->nullable();
            $table->char('fx_base', 3)->nullable();
            $table->char('fx_quote', 3)->nullable();
            $table->unsignedBigInteger('fx_rate_minor')->nullable();
            $table->bigInteger('reporting_amount_minor')->nullable();
            $table->string('payee')->nullable();
            $table->string('approval_status', 16)->default('recorded');
            $table->string('payment_status', 16)->default('paid');
        });

        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->char('base_currency', 3);
            $table->char('quote_currency', 3);
            $table->unsignedBigInteger('rate_minor');
            $table->timestamp('effective_from');
            $table->timestamp('effective_to')->nullable();
            $table->uuid('created_by');
            $table->timestamps();
            $table->index(['organization_id', 'branch_id']);
        });

        DB::statement('create unique index exchange_rates_one_open on exchange_rates (organization_id, branch_id, base_currency, quote_currency) where effective_to is null');

        Schema::create('expense_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('code', 32);
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('default_type', 16);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
        });

        Schema::create('expense_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->uuid('category_id');
            $table->string('frequency', 16);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->date('next_due_on');
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('payee')->nullable();
            $table->string('description');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['organization_id', 'branch_id', 'next_due_on']);
        });

        Schema::create('service_bays', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->string('name');
            $table->string('code', 32);
            $table->string('status', 16);
            $table->string('supervisor')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'branch_id', 'code']);
        });

        Schema::create('service_offerings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('name');
            $table->string('code', 32);
            $table->bigInteger('price_minor');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
        });

        Schema::create('vehicles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('customer_id');
            $table->string('registration', 32);
            $table->string('details')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'registration']);
        });

        Schema::create('work_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->uuid('service_bay_id')->nullable();
            $table->uuid('customer_id');
            $table->uuid('vehicle_id');
            $table->string('number', 32);
            $table->string('status', 16);
            $table->unsignedInteger('odometer')->nullable();
            $table->string('technician')->nullable();
            $table->bigInteger('labor_minor')->default(0);
            $table->bigInteger('invoiced_minor')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('opened_by');
            $table->timestamp('opened_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('invoiced_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'branch_id', 'status']);
        });

        Schema::create('work_order_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('work_order_id');
            $table->string('kind', 16);
            $table->string('description');
            $table->bigInteger('quantity_milli')->default(0);
            $table->bigInteger('amount_minor')->default(0);
            $table->uuid('store_id')->nullable();
            $table->uuid('store_product_id')->nullable();
            $table->boolean('issued')->default(false);
            $table->timestamps();
        });

        Schema::create('work_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('work_order_id');
            $table->bigInteger('amount_minor');
            $table->string('kind', 16);
            $table->string('idempotency_key', 64)->nullable();
            $table->uuid('recorded_by');
            $table->timestamps();
            $table->unique(['organization_id', 'idempotency_key']);
        });

        Schema::create('stores', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->string('name');
            $table->string('code', 32);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'branch_id', 'code']);
        });

        Schema::create('store_products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('name');
            $table->string('code', 32);
            $table->string('unit', 16);
            $table->string('category')->nullable();
            $table->bigInteger('reorder_milli')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
        });

        Schema::create('store_balances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('store_id');
            $table->uuid('product_id');
            $table->bigInteger('quantity_milli')->default(0);
            $table->bigInteger('average_cost_minor')->default(0);
            $table->timestamps();
            $table->unique(['store_id', 'product_id']);
        });

        Schema::create('store_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->uuid('store_id');
            $table->uuid('product_id');
            $table->string('kind', 32);
            $table->bigInteger('quantity_milli');
            $table->bigInteger('unit_cost_minor');
            $table->string('reference_type')->nullable();
            $table->uuid('reference_id')->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->string('notes')->nullable();
            $table->uuid('created_by');
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->unique(['organization_id', 'idempotency_key']);
        });

        Schema::table('fuel_transfers', function (Blueprint $table) {
            $table->unsignedBigInteger('requested_milli')->default(0);
            $table->uuid('destination_branch_id')->nullable();
            $table->bigInteger('unit_cost_minor')->nullable();
            $table->uuid('dispatched_by')->nullable();
            $table->uuid('received_by')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('completed_at')->nullable();
        });

        Schema::create('store_transfers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('number', 32);
            $table->uuid('source_store_id');
            $table->uuid('destination_store_id');
            $table->uuid('product_id');
            $table->bigInteger('requested_milli');
            $table->bigInteger('dispatched_milli')->nullable();
            $table->bigInteger('received_milli')->nullable();
            $table->bigInteger('unit_cost_minor')->nullable();
            $table->bigInteger('variance_milli')->nullable();
            $table->string('status', 16);
            $table->uuid('requested_by');
            $table->uuid('approved_by')->nullable();
            $table->uuid('dispatched_by')->nullable();
            $table->uuid('received_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_transfers');
        Schema::table('fuel_transfers', function (Blueprint $table) {
            $table->dropColumn([
                'requested_milli', 'destination_branch_id', 'unit_cost_minor',
                'dispatched_by', 'received_by', 'dispatched_at', 'received_at', 'completed_at',
            ]);
        });
        Schema::dropIfExists('store_movements');
        Schema::dropIfExists('store_balances');
        Schema::dropIfExists('store_products');
        Schema::dropIfExists('stores');
        Schema::dropIfExists('work_payments');
        Schema::dropIfExists('work_order_lines');
        Schema::dropIfExists('work_orders');
        Schema::dropIfExists('vehicles');
        Schema::dropIfExists('service_offerings');
        Schema::dropIfExists('service_bays');
        Schema::dropIfExists('expense_schedules');
        Schema::dropIfExists('expense_categories');
        DB::statement('drop index if exists exchange_rates_one_open');
        Schema::dropIfExists('exchange_rates');

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn([
                'category_id', 'schedule_id', 'currency', 'fx_base', 'fx_quote', 'fx_rate_minor',
                'reporting_amount_minor', 'payee', 'approval_status', 'payment_status',
            ]);
        });
        Schema::table('fuel_sales', function (Blueprint $table) {
            $table->dropColumn(['currency', 'fx_base', 'fx_quote', 'fx_rate_minor', 'reporting_total_minor']);
        });
        Schema::table('fuel_prices', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
        Schema::table('stations', function (Blueprint $table) {
            $table->dropColumn('operations');
        });
    }
};
