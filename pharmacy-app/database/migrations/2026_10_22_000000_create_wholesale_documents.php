<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wholesale documents (Retail R4): price lists a customer buys at,
 * quotations that become sales orders, and delivery notes that take the
 * goods out of stock and invoice exactly what went (as a sale on account).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_lists', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->string('application', 32);
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'application', 'name']);
        });

        Schema::create('price_list_prices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('price_list_id')->index();
            $table->uuid('item_id');
            // Null: the price of one unit.
            $table->uuid('item_pack_id')->nullable();
            $table->unsignedBigInteger('price_minor');
            $table->timestamps();

            $table->index(['price_list_id', 'item_id']);
        });

        Schema::table('customers', function (Blueprint $table) {
            // Null: the shop's own prices.
            $table->uuid('price_list_id')->nullable()->after('payment_terms_days');
        });

        Schema::create('sales_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->string('application', 32);
            $table->uuid('branch_id');
            $table->uuid('customer_id');
            $table->uuid('price_list_id')->nullable();
            $table->string('quote_number', 16);
            // Given when the quotation is confirmed.
            $table->string('order_number', 16)->nullable();
            $table->string('status', 24);
            $table->date('valid_until');
            // The customer's own order number.
            $table->string('customer_reference', 64)->nullable();
            $table->string('notes', 500)->nullable();
            $table->unsignedBigInteger('total_minor');
            $table->uuid('created_by');
            $table->uuid('confirmed_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'quote_number']);
            $table->unique(['organization_id', 'order_number']);
            $table->index(['organization_id', 'application', 'branch_id', 'status']);
            $table->index(['organization_id', 'customer_id']);
        });

        Schema::create('sales_order_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('sales_order_id')->index();
            $table->uuid('item_id');
            $table->string('pack_name', 32)->nullable();
            $table->unsignedInteger('units_per_pack')->default(1);
            // In packs when pack_name is set, units otherwise.
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('quantity_delivered')->default(0);
            // Per pack (or unit), before the discount.
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedTinyInteger('discount_percent')->default(0);
            $table->unsignedBigInteger('line_total_minor');
        });

        Schema::create('delivery_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->string('application', 32);
            $table->uuid('branch_id');
            $table->uuid('sales_order_id')->index();
            $table->string('number', 16);
            // The invoice: a sale on account of exactly what was delivered.
            $table->uuid('sale_id')->unique();
            $table->string('received_by_name')->nullable();
            $table->string('note', 500)->nullable();
            $table->uuid('delivered_by');
            $table->timestamp('delivered_at');
            $table->uuid('submission_id')->nullable()->unique();
            $table->timestamps();

            $table->unique(['organization_id', 'number']);
        });

        Schema::create('delivery_note_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('delivery_note_id')->index();
            $table->uuid('sales_order_line_id')->index();
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('amount_minor');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_note_lines');
        Schema::dropIfExists('delivery_notes');
        Schema::dropIfExists('sales_order_lines');
        Schema::dropIfExists('sales_orders');

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('price_list_id');
        });

        Schema::dropIfExists('price_list_prices');
        Schema::dropIfExists('price_lists');
    }
};
