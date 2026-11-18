<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Cash handovers: the cash of a closed till handed by its cashier to a
 * manager, who counts it and confirms what they received. One per till.
 *
 * Branch settings: what each branch does its own way in the restaurant
 * (receipt lines, the usual starting cash, the expected time for
 * the kitchen). The branch itself (name, address, open or closed) is
 * kept in the workspace, for every app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_handovers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->string('number', 32);
            $table->foreignUuid('till_shift_id')->constrained('till_shifts');
            $table->string('status', 16);
            $table->unsignedBigInteger('amount_minor');
            $table->string('note')->nullable();
            $table->foreignUuid('handed_by')->constrained('users');
            $table->timestamp('handed_at');
            $table->foreignUuid('received_by')->nullable()->constrained('users');
            $table->timestamp('received_at')->nullable();
            $table->unsignedBigInteger('received_minor')->nullable();
            $table->string('receiving_note')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'number']);
            $table->unique('till_shift_id');
            $table->index(['branch_id', 'status', 'handed_at']);
        });

        Schema::create('branch_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->string('receipt_header')->nullable();
            $table->string('receipt_footer')->nullable();
            $table->string('phone', 64)->nullable();
            $table->unsignedBigInteger('default_float_minor')->nullable();
            $table->unsignedSmallInteger('slow_ticket_minutes')->nullable();
            $table->foreignUuid('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['organization_id', 'branch_id']);
        });

        // Movements across all drinks, newest first, per branch.
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->index(['branch_id', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE cash_handovers ADD CONSTRAINT cash_handovers_status CHECK (status IN ('pending', 'received'))");
        }
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex(['branch_id', 'created_at']);
        });
        Schema::dropIfExists('branch_settings');
        Schema::dropIfExists('cash_handovers');
    }
};
