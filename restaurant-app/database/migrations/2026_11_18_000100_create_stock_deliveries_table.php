<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Drinks delivered by a supplier: one delivery note, a line per drink at
 * what it cost. Each line is a stock movement of the delivery; Finance is
 * told of what is owed (finance.bill.received).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->string('number', 32);
            $table->string('supplier_name');
            $table->string('supplier_reference', 128)->nullable();
            $table->date('received_on');
            $table->unsignedBigInteger('total_minor');
            $table->string('note')->nullable();
            $table->foreignUuid('received_by')->constrained('users');
            $table->timestamps();
            $table->unique(['organization_id', 'number']);
            $table->index(['branch_id', 'received_on']);
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignUuid('delivery_id')->nullable()->after('bill_id')->constrained('stock_deliveries');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delivery_id');
        });
        Schema::dropIfExists('stock_deliveries');
    }
};
