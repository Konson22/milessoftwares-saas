<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Procurement: a request, the quotes that answer it, the order placed from one
 * of them, and what was received. The order's commitment is an ordinary expense
 * (App\Actions\Spending), so budgets and grants count it once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('project_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('grant_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('number', 20);
            $table->string('title', 200);
            $table->text('justification');
            $table->string('category', 32);
            $table->date('needed_by')->nullable();
            $table->string('status', 16);
            $table->bigInteger('estimate_minor');
            $table->foreignUuid('requested_by')->constrained('users');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignUuid('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignUuid('selected_quote_id')->nullable();
            $table->text('selection_reason')->nullable();
            $table->foreignUuid('selected_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('purchase_request_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('purchase_request_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->decimal('quantity', 12, 2);
            $table->string('unit', 32)->nullable();
            $table->bigInteger('unit_price_minor');
            $table->bigInteger('amount_minor');
            $table->timestamps();
        });

        Schema::create('purchase_quotes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('purchase_request_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('supplier_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount_minor');
            $table->string('reference', 100)->nullable();
            $table->date('valid_until')->nullable();
            $table->string('original_name')->nullable();
            $table->string('path')->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('purchase_request_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('purchase_quote_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('expense_id')->constrained()->restrictOnDelete();
            $table->string('number', 20);
            $table->bigInteger('amount_minor');
            $table->text('terms')->nullable();
            $table->string('invoice_reference', 100)->nullable();
            $table->date('invoice_on')->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();
            $table->unique(['organization_id', 'number']);
            $table->unique('expense_id');
        });

        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('purchase_order_id')->constrained()->restrictOnDelete();
            $table->date('received_on');
            $table->text('note')->nullable();
            $table->foreignUuid('received_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('goods_receipt_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('goods_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('purchase_request_line_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_lines');
        Schema::dropIfExists('goods_receipts');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('purchase_quotes');
        Schema::dropIfExists('purchase_request_lines');
        Schema::dropIfExists('purchase_requests');
    }
};
