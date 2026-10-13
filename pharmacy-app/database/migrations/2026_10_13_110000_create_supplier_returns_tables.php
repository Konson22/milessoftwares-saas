<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Goods sent back to the supplier they came from: always against a
        // goods receipt, at the cost they were received at.
        Schema::create('supplier_returns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->uuid('branch_id');
            $table->string('number', 32);
            $table->foreignUuid('supplier_id')->constrained();
            $table->foreignUuid('goods_receipt_id')->constrained();
            $table->date('returned_on');
            $table->string('reason', 255);
            $table->bigInteger('total_minor');
            $table->foreignUuid('returned_by')->constrained('users');
            $table->timestamps();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'branch_id', 'returned_on']);
        });

        Schema::create('supplier_return_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('supplier_return_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('goods_receipt_line_id')->constrained();
            $table->foreignUuid('medicine_id')->constrained();
            $table->foreignUuid('batch_id')->constrained();
            $table->integer('quantity');
            $table->bigInteger('unit_cost_minor');
            $table->bigInteger('line_total_minor');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_return_lines');
        Schema::dropIfExists('supplier_returns');
    }
};
