<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How each branch's counter works: its sales workflow (a direct sale, or an
 * order that is paid and dispensed in an order the branch chooses), when it
 * must know who the customer is, and what it prints. Counter orders carry a
 * sale from the order to its payment and hand-over; every printed document
 * is logged, so automatic printing happens once and later copies are
 * marked as reprints.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_counter_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->string('application', 32);
            $table->uuid('branch_id');
            // direct | order_pay_dispense | order_dispense_pay | order_pay_collect
            $table->string('workflow', 24)->default('direct');

            // When the customer must be named: never by default.
            $table->boolean('require_customer_always')->default(false);
            $table->boolean('require_customer_for_restricted')->default(false);
            $table->unsignedBigInteger('require_customer_above_minor')->nullable();

            $table->boolean('print_receipt_after_payment')->default(false);
            $table->boolean('print_order_slip')->default(false);
            $table->boolean('print_collection_slip')->default(false);
            $table->boolean('print_receipt_after_dispensing')->default(false);
            $table->string('printer_name', 120)->nullable();
            // 58mm | 80mm | a4
            $table->string('paper_size', 8)->default('80mm');
            $table->string('receipt_header', 500)->nullable();
            $table->string('receipt_footer', 500)->nullable();
            $table->string('business_name')->nullable();
            $table->string('address', 500)->nullable();
            $table->string('contact', 255)->nullable();
            // A small image as a data: URL; receipts carry no remote images.
            $table->text('logo')->nullable();
            $table->boolean('show_logo')->default(true);
            $table->boolean('show_address')->default(true);
            $table->boolean('show_contact')->default(true);
            $table->boolean('show_customer')->default(true);
            $table->boolean('show_staff')->default(true);
            $table->boolean('show_barcode')->default(true);
            $table->boolean('slip_prices')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'application', 'branch_id']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->string('customer_phone', 32)->nullable()->after('customer_name');
        });

        Schema::create('counter_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->string('application', 32);
            $table->uuid('branch_id');
            $table->string('number', 16);
            // The branch's workflow when the order was taken: it keeps it.
            $table->string('workflow', 24);
            // open → (paid | handed_over) → completed; cancelled
            $table->string('status', 16);
            $table->string('customer_name')->nullable();
            $table->string('customer_phone', 32)->nullable();
            $table->uuid('customer_id')->nullable();
            $table->char('currency', 3);
            // As priced when ordered; the payment prices it again.
            $table->unsignedBigInteger('total_minor');
            $table->uuid('submission_id')->nullable();
            $table->foreignUuid('ordered_by')->constrained('users');
            $table->timestamp('ordered_at');
            $table->foreignUuid('sale_id')->nullable()->constrained();
            $table->timestamp('paid_at')->nullable();
            $table->foreignUuid('handed_over_by')->nullable()->constrained('users');
            $table->timestamp('handed_over_at')->nullable();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users');
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'number']);
            $table->unique(['organization_id', 'submission_id']);
            $table->index(['organization_id', 'branch_id', 'application', 'status']);
        });

        Schema::create('counter_order_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('counter_order_id')->constrained()->cascadeOnDelete();
            $table->uuid('item_id');
            $table->uuid('item_pack_id')->nullable();
            // In packs when a pack is named, otherwise in units.
            $table->unsignedInteger('quantity');
            $table->unsignedTinyInteger('discount_percent')->default(0);
            $table->unsignedBigInteger('line_total_minor');
            // Dispensed before payment: the batches the stock came from.
            $table->json('taken')->nullable();
            $table->timestamps();
        });

        Schema::create('printed_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->uuid('branch_id');
            // receipt | order_slip | collection_slip
            $table->string('document', 24);
            // The sale or counter order it prints.
            $table->string('reference_type', 24);
            $table->uuid('reference_id');
            $table->unsignedSmallInteger('copy');
            $table->boolean('automatic')->default(false);
            $table->foreignUuid('printed_by')->constrained('users');
            $table->timestamp('printed_at');
            $table->timestamps();

            $table->unique(['organization_id', 'document', 'reference_id', 'copy']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('printed_documents');
        Schema::dropIfExists('counter_order_lines');
        Schema::dropIfExists('counter_orders');

        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('customer_phone');
        });

        Schema::dropIfExists('branch_counter_settings');
    }
};
