<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_lines', function (Blueprint $table) {
            $table->char('currency', 3)->nullable();
            $table->char('fx_base', 3)->nullable();
            $table->char('fx_quote', 3)->nullable();
            $table->unsignedBigInteger('fx_rate_minor')->nullable();
            $table->bigInteger('reporting_unit_cost_minor')->nullable();
        });

        Schema::table('fuel_receipts', function (Blueprint $table) {
            $table->char('currency', 3)->nullable();
            $table->char('fx_base', 3)->nullable();
            $table->char('fx_quote', 3)->nullable();
            $table->unsignedBigInteger('fx_rate_minor')->nullable();
            $table->bigInteger('reporting_total_minor')->nullable();
            $table->bigInteger('reporting_unit_cost_minor')->nullable();
        });

        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->char('currency', 3)->nullable();
            $table->char('fx_base', 3)->nullable();
            $table->char('fx_quote', 3)->nullable();
            $table->unsignedBigInteger('fx_rate_minor')->nullable();
            $table->bigInteger('reporting_amount_minor')->nullable();
        });

        Schema::table('credit_entries', function (Blueprint $table) {
            $table->char('currency', 3)->nullable();
            $table->char('fx_base', 3)->nullable();
            $table->char('fx_quote', 3)->nullable();
            $table->unsignedBigInteger('fx_rate_minor')->nullable();
            $table->bigInteger('reporting_amount_minor')->nullable();
        });

        Schema::table('cash_movements', function (Blueprint $table) {
            $table->char('currency', 3)->nullable();
        });

        Schema::table('sale_payments', function (Blueprint $table) {
            $table->char('currency', 3)->nullable();
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->json('counted_by_currency')->nullable();
        });

        Schema::table('expense_schedules', function (Blueprint $table) {
            $table->string('status', 16)->default('active');
            $table->uuid('created_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('expense_schedules', function (Blueprint $table) {
            $table->dropColumn(['status', 'created_by']);
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn('counted_by_currency');
        });

        Schema::table('sale_payments', function (Blueprint $table) {
            $table->dropColumn('currency');
        });

        Schema::table('cash_movements', function (Blueprint $table) {
            $table->dropColumn('currency');
        });

        Schema::table('credit_entries', function (Blueprint $table) {
            $table->dropColumn(['currency', 'fx_base', 'fx_quote', 'fx_rate_minor', 'reporting_amount_minor']);
        });

        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->dropColumn(['currency', 'fx_base', 'fx_quote', 'fx_rate_minor', 'reporting_amount_minor']);
        });

        Schema::table('fuel_receipts', function (Blueprint $table) {
            $table->dropColumn(['currency', 'fx_base', 'fx_quote', 'fx_rate_minor', 'reporting_total_minor', 'reporting_unit_cost_minor']);
        });

        Schema::table('purchase_lines', function (Blueprint $table) {
            $table->dropColumn(['currency', 'fx_base', 'fx_quote', 'fx_rate_minor', 'reporting_unit_cost_minor']);
        });
    }
};
