<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->uuid('branch_id');
            $table->string('number', 32);
            // completed → voided
            $table->string('status', 16);
            $table->string('customer_name')->nullable();
            $table->char('currency', 3);
            $table->bigInteger('total_minor');
            $table->string('payment_method', 16);
            $table->string('payment_reference', 64)->nullable();
            $table->bigInteger('amount_tendered_minor')->nullable();
            $table->bigInteger('change_minor')->nullable();
            $table->foreignUuid('sold_by')->constrained('users');
            $table->timestamp('sold_at');
            $table->foreignUuid('voided_by')->nullable()->constrained('users');
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'branch_id', 'sold_at']);
        });

        // One line per batch the stock came from: a medicine sold from two
        // batches is two lines.
        Schema::create('sale_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('medicine_id')->constrained();
            $table->foreignUuid('batch_id')->constrained();
            $table->integer('quantity');
            $table->bigInteger('unit_price_minor');
            $table->bigInteger('line_total_minor');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_lines');
        Schema::dropIfExists('sales');
    }
};
