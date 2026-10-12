<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Running numbers per organization and document type (PO-00001, GRN-00001).
        Schema::create('document_numbers', function (Blueprint $table) {
            $table->uuid('organization_id');
            $table->string('type', 16);
            $table->unsignedBigInteger('last_number');

            $table->primary(['organization_id', 'type']);
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->string('name');
            $table->string('phone', 64)->nullable();
            $table->string('email')->nullable();
            $table->string('address', 500)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->uuid('branch_id');
            $table->string('number', 32);
            $table->foreignUuid('supplier_id')->constrained();
            // draft → ordered → partly_received → received, or cancelled
            $table->string('status', 16);
            $table->date('expected_on')->nullable();
            $table->string('notes', 1000)->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamp('ordered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'branch_id', 'status']);
        });

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('medicine_id')->constrained();
            $table->integer('quantity_ordered');
            $table->integer('quantity_received')->default(0);
            $table->bigInteger('unit_cost_minor');
        });

        // Goods received notes: what arrived, into which batches, owed to whom.
        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->uuid('branch_id');
            $table->string('number', 32);
            $table->foreignUuid('supplier_id')->constrained();
            $table->foreignUuid('purchase_order_id')->nullable()->constrained();
            $table->string('supplier_reference', 128)->nullable();
            $table->date('received_on');
            $table->bigInteger('total_minor');
            $table->foreignUuid('received_by')->constrained('users');
            $table->timestamps();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'branch_id', 'received_on']);
        });

        Schema::create('goods_receipt_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('goods_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('medicine_id')->constrained();
            $table->foreignUuid('batch_id')->constrained();
            $table->foreignUuid('purchase_order_line_id')->nullable()->constrained();
            $table->string('batch_number', 64);
            $table->date('expires_on');
            $table->integer('quantity');
            $table->bigInteger('unit_cost_minor');
            $table->bigInteger('line_total_minor');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_lines');
        Schema::dropIfExists('goods_receipts');
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('document_numbers');
    }
};
