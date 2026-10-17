<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Part of a sale brought back: what came back from which sale line, what
 * it was worth then, whether it went back on the shelf, and the refund
 * (given from the open till of whoever took the return).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_returns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->uuid('branch_id');
            $table->foreignUuid('sale_id')->constrained();
            $table->string('number', 32);
            $table->string('reason', 255);
            $table->char('currency', 3);
            $table->bigInteger('total_minor');
            $table->string('refund_method', 16);
            $table->foreignUuid('till_session_id')->nullable()->constrained();
            $table->foreignUuid('returned_by')->constrained('users');
            $table->timestamp('returned_at');
            $table->timestamps();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'branch_id', 'returned_at']);
        });

        Schema::create('sale_return_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('sale_return_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('sale_line_id')->constrained();
            $table->foreignUuid('item_id')->constrained();
            $table->foreignUuid('batch_id')->constrained();
            $table->integer('quantity');
            $table->bigInteger('amount_minor');
            // What the units cost: back into inventory when restocked.
            $table->bigInteger('cost_minor');
            $table->boolean('restocked');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_return_lines');
        Schema::dropIfExists('sale_returns');
    }
};
