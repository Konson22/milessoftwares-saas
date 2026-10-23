<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock-take count sheets: the batches of a branch (or one category) on a
 * sheet, counted line by line, the differences posted as count corrections
 * when the count is completed. Each line keeps what was on hand when it was
 * counted, so sales during the count do not show as differences.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_counts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->uuid('branch_id');
            // The industry module that counts (pharmacy, retail): its items only.
            $table->string('application', 32);
            $table->string('number', 32);
            $table->string('category', 64)->nullable();
            $table->string('note', 255)->nullable();
            // open → completed | cancelled
            $table->string('status', 16);
            $table->foreignUuid('started_by')->constrained('users');
            $table->timestamp('started_at');
            $table->foreignUuid('closed_by')->nullable()->constrained('users');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'branch_id', 'application', 'status']);
        });

        Schema::create('stock_count_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->foreignUuid('stock_count_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('item_id')->constrained();
            $table->foreignUuid('batch_id')->constrained();
            // On hand when the sheet was made, then when the line was counted.
            $table->integer('expected');
            $table->integer('counted')->nullable();
            $table->foreignUuid('counted_by')->nullable()->constrained('users');
            $table->timestamp('counted_at')->nullable();
            // Posted on completion: counted less expected.
            $table->integer('difference')->nullable();
            $table->timestamps();

            $table->unique(['stock_count_id', 'batch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_count_lines');
        Schema::dropIfExists('stock_counts');
    }
};
