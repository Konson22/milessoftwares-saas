<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Gap-free running numbers per organization (App\Support\DocumentNumbers).
        Schema::create('document_numbers', function (Blueprint $table) {
            $table->uuid('organization_id');
            $table->string('type', 32);
            $table->unsignedBigInteger('last_number');
            $table->primary(['organization_id', 'type']);
        });

        // The menu: the organization's, shared by every branch. Dishes are
        // made to order and keep no stock; drinks are bought and counted.
        Schema::create('menu_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->string('kind', 16);
            $table->string('name');
            $table->string('section', 64)->nullable();
            $table->string('unit', 32);
            $table->unsignedBigInteger('price_minor');
            $table->string('barcode', 64)->nullable();
            $table->unsignedInteger('reorder_level')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'barcode']);
        });

        // Drinks on hand at a branch, at their average cost.
        Schema::create('stock_levels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->uuid('branch_id');
            $table->foreignUuid('menu_item_id')->constrained();
            $table->integer('quantity')->default(0);
            $table->unsignedBigInteger('average_cost_minor')->default(0);
            $table->timestamps();
            $table->unique(['branch_id', 'menu_item_id']);
        });

        Schema::create('dining_tables', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->string('name', 32);
            $table->string('area', 32)->nullable();
            $table->unsignedSmallInteger('seats');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'branch_id', 'name']);
        });

        // A cashier's shift at a branch: the drawer opened with a float and
        // closed with a count.
        Schema::create('till_shifts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->string('number', 32);
            $table->string('status', 16);
            $table->unsignedBigInteger('opening_float_minor');
            $table->foreignUuid('opened_by')->constrained('users');
            $table->timestamp('opened_at');
            $table->foreignUuid('closed_by')->nullable()->constrained('users');
            $table->timestamp('closed_at')->nullable();
            $table->bigInteger('expected_cash_minor')->nullable();
            $table->unsignedBigInteger('counted_cash_minor')->nullable();
            $table->string('closing_note')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'number']);
            $table->index(['branch_id', 'status']);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->string('number', 32);
            $table->string('service', 16);
            $table->foreignUuid('dining_table_id')->nullable()->constrained();
            $table->unsignedSmallInteger('guests')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('status', 16);
            $table->foreignUuid('opened_by')->constrained('users');
            $table->timestamp('opened_at');
            $table->foreignUuid('closed_by')->nullable()->constrained('users');
            $table->timestamp('closed_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'number']);
            $table->index(['branch_id', 'status']);
            $table->index(['branch_id', 'opened_at']);
        });

        Schema::create('kitchen_tickets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->foreignUuid('order_id')->constrained();
            $table->string('number', 32);
            $table->string('status', 16);
            $table->foreignUuid('sent_by')->constrained('users');
            $table->timestamp('sent_at');
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('served_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'number']);
            $table->index(['branch_id', 'status']);
        });

        Schema::create('order_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('menu_item_id')->constrained();
            $table->unsignedInteger('quantity');
            $table->string('note', 160)->nullable();
            $table->foreignUuid('kitchen_ticket_id')->nullable()->constrained();
            $table->foreignUuid('added_by')->constrained('users');
            $table->timestamp('added_at');
            $table->foreignUuid('voided_by')->nullable()->constrained('users');
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();
        });

        // The paid bill of an order: what Finance is told was sold and taken.
        Schema::create('bills', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->foreignUuid('order_id')->unique()->constrained();
            $table->foreignUuid('till_shift_id')->constrained();
            $table->string('number', 32);
            $table->char('currency', 3);
            $table->unsignedBigInteger('total_minor');
            $table->unsignedBigInteger('cost_minor');
            $table->string('payment_method', 16);
            $table->unsignedBigInteger('tendered_minor')->nullable();
            $table->unsignedBigInteger('change_minor')->default(0);
            $table->string('payment_reference', 64)->nullable();
            $table->string('customer_name')->nullable();
            $table->string('status', 16);
            $table->foreignUuid('paid_by')->constrained('users');
            $table->timestamp('paid_at');
            $table->foreignUuid('voided_by')->nullable()->constrained('users');
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            // The till cash was given back from, when a cash bill is voided.
            $table->foreignUuid('refund_till_shift_id')->nullable()->constrained('till_shifts');
            $table->timestamps();
            $table->unique(['organization_id', 'number']);
            $table->index(['branch_id', 'paid_at']);
        });

        Schema::create('bill_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('bill_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('menu_item_id')->constrained();
            $table->string('name');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedBigInteger('total_minor');
            $table->unsignedBigInteger('cost_minor');
        });

        // What moved a branch's drinks, signed: positive stock gained.
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->foreignUuid('menu_item_id')->constrained();
            $table->string('type', 16);
            $table->integer('quantity');
            $table->unsignedBigInteger('unit_cost_minor');
            $table->string('reason', 32)->nullable();
            $table->string('note')->nullable();
            $table->foreignUuid('bill_id')->nullable()->constrained();
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamp('created_at');
            $table->index(['branch_id', 'menu_item_id', 'created_at']);
        });

        // A table has one open order, and a cashier one open till per branch,
        // whatever runs alongside.
        DB::statement("CREATE UNIQUE INDEX orders_one_open_per_table ON orders (dining_table_id) WHERE status = 'open'");
        DB::statement("CREATE UNIQUE INDEX till_shifts_one_open_per_cashier ON till_shifts (branch_id, opened_by) WHERE status = 'open'");

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE order_lines ADD CONSTRAINT order_lines_quantity_positive CHECK (quantity > 0)');
            DB::statement('ALTER TABLE bill_lines ADD CONSTRAINT bill_lines_quantity_positive CHECK (quantity > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('bill_lines');
        Schema::dropIfExists('bills');
        Schema::dropIfExists('order_lines');
        Schema::dropIfExists('kitchen_tickets');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('till_shifts');
        Schema::dropIfExists('dining_tables');
        Schema::dropIfExists('stock_levels');
        Schema::dropIfExists('menu_items');
        Schema::dropIfExists('document_numbers');
    }
};
