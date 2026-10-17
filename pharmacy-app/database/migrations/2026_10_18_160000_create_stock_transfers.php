<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock sent from one branch to another: it leaves the sending branch when
 * sent and arrives, in the same batches, when the other branch receives it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->string('number', 32);
            $table->uuid('from_branch_id');
            $table->uuid('to_branch_id');
            // in_transit → received | cancelled
            $table->string('status', 16);
            $table->string('note', 255)->nullable();
            $table->foreignUuid('sent_by')->constrained('users');
            $table->timestamp('sent_at');
            $table->foreignUuid('received_by')->nullable()->constrained('users');
            $table->timestamp('received_at')->nullable();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'from_branch_id', 'status']);
            $table->index(['organization_id', 'to_branch_id', 'status']);
        });

        Schema::create('stock_transfer_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('item_id')->constrained();
            // The sending batch, and the one it arrived in.
            $table->foreignUuid('batch_id')->constrained();
            $table->foreignUuid('received_batch_id')->nullable()->constrained('batches');
            $table->string('batch_number', 64);
            $table->date('expires_on');
            $table->integer('unit_cost_minor');
            $table->integer('quantity');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_lines');
        Schema::dropIfExists('stock_transfers');
    }
};
