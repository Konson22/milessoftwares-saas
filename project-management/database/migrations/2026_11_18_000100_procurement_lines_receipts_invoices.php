<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Procurement against budget lines (ADR-0020). Additive only: no row is
 * deleted and no amount changes.
 *
 * - budget_lines.line_key: one line across budget versions (backfilled
 *   with the line's own id; a revision copies it).
 * - purchase_requests / expenses .budget_line_key: the exact line charged
 *   (null on older rows, which count by category as before).
 * - goods_receipt_lines.rejected_quantity, goods_receipts evidence file.
 * - purchase_orders close (short) facts.
 * - supplier_invoices, one live invoice per supplier and number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budget_lines', function (Blueprint $table) {
            $table->uuid('line_key')->nullable();
            $table->index(['project_id', 'line_key']);
        });
        DB::table('budget_lines')->whereNull('line_key')->update(['line_key' => DB::raw('id')]);

        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->uuid('budget_line_key')->nullable();
        });
        Schema::table('expenses', function (Blueprint $table) {
            $table->uuid('budget_line_key')->nullable();
            $table->index(['project_id', 'budget_line_key']);
        });

        Schema::table('goods_receipt_lines', function (Blueprint $table) {
            $table->decimal('rejected_quantity', 12, 2)->default(0);
        });
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->string('original_name')->nullable();
            $table->string('path')->nullable();
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable();
            $table->foreignUuid('closed_by')->nullable()->constrained('users');
            $table->text('close_reason')->nullable();
        });

        Schema::create('supplier_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('purchase_order_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('supplier_id')->constrained()->restrictOnDelete();
            $table->string('number', 100);
            // Lower case, spaces removed: "INV 001" and "inv001" are one invoice.
            $table->string('number_key', 100);
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->date('invoice_on');
            $table->date('due_on')->nullable();
            $table->string('original_name')->nullable();
            $table->string('path')->nullable();
            $table->string('status', 16)->default('recorded');
            $table->foreignUuid('recorded_by')->constrained('users');
            $table->timestamp('voided_at')->nullable();
            $table->foreignUuid('voided_by')->nullable()->constrained('users');
            $table->text('void_reason')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'purchase_order_id']);
        });
        // Both drivers take a partial index: a voided invoice frees its number.
        DB::statement("CREATE UNIQUE INDEX supplier_invoices_live_number ON supplier_invoices (organization_id, supplier_id, number_key) WHERE status = 'recorded'");

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE supplier_invoices ADD CONSTRAINT supplier_invoices_amount_positive CHECK (amount_minor > 0)');
            DB::statement("ALTER TABLE supplier_invoices ADD CONSTRAINT supplier_invoices_status CHECK (status IN ('recorded', 'voided'))");
            DB::statement("ALTER TABLE supplier_invoices ADD CONSTRAINT supplier_invoices_voided_facts CHECK (status = 'recorded' OR (voided_at IS NOT NULL AND voided_by IS NOT NULL AND void_reason IS NOT NULL))");
            DB::statement('ALTER TABLE goods_receipt_lines ADD CONSTRAINT goods_receipt_lines_quantities CHECK (quantity >= 0 AND rejected_quantity >= 0 AND quantity + rejected_quantity > 0)');
            DB::statement('ALTER TABLE purchase_orders ADD CONSTRAINT purchase_orders_closed_facts CHECK (closed_at IS NULL OR (closed_by IS NOT NULL AND close_reason IS NOT NULL))');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE goods_receipt_lines DROP CONSTRAINT IF EXISTS goods_receipt_lines_quantities');
            DB::statement('ALTER TABLE purchase_orders DROP CONSTRAINT IF EXISTS purchase_orders_closed_facts');
        }
        Schema::dropIfExists('supplier_invoices');
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('closed_by');
            $table->dropColumn(['closed_at', 'close_reason']);
        });
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->dropColumn(['original_name', 'path']);
        });
        Schema::table('goods_receipt_lines', function (Blueprint $table) {
            $table->dropColumn('rejected_quantity');
        });
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'budget_line_key']);
            $table->dropColumn('budget_line_key');
        });
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropColumn('budget_line_key');
        });
        Schema::table('budget_lines', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'line_key']);
            $table->dropColumn('line_key');
        });
    }
};
