<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batches put on hold (quarantined while in doubt, or recalled by the
 * maker) are never sold; damaged and expired stock leaves the shelf through
 * a disposal that a second person approves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('batches', function (Blueprint $table) {
            // available | quarantined | recalled
            $table->string('status', 16)->default('available');
            $table->string('hold_reason', 255)->nullable();
            $table->foreignUuid('held_by')->nullable()->constrained('users');
            $table->timestamp('held_at')->nullable();
        });

        Schema::create('stock_disposals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->uuid('branch_id');
            $table->string('number', 32);
            $table->foreignUuid('item_id')->constrained();
            $table->foreignUuid('batch_id')->constrained();
            $table->integer('quantity');
            $table->string('reason', 32);
            $table->string('note', 255)->nullable();
            // pending → approved | rejected
            $table->string('status', 16);
            $table->foreignUuid('requested_by')->constrained('users');
            $table->timestamp('requested_at');
            $table->foreignUuid('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 255)->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_disposals');

        Schema::table('batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('held_by');
            $table->dropColumn(['status', 'hold_reason', 'held_at']);
        });
    }
};
