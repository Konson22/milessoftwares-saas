<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Petty cash as an imprest fund (App\Finance\PettyCash). Each fund is one
 * petty cash account of the chart, and its balance is that account's: there
 * is no second ledger. Vouchers post when they are recorded; the fund is
 * brought back to its float by a transfer payment request.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The most one petty cash voucher may be, in the books' currency.
        Schema::table('workspaces', function (Blueprint $table) {
            $table->bigInteger('petty_cash_max_minor')->nullable();
        });

        Schema::create('petty_cash_funds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('name', 120);
            $table->foreignUuid('account_id')->unique()->constrained('accounts');
            // Null for a fund moved in from Projects until a manager names one.
            $table->foreignUuid('custodian_id')->nullable()->constrained('users');
            // In the account's currency.
            $table->bigInteger('float_minor');
            $table->bigInteger('replenish_at_minor')->nullable();
            $table->string('status', 16)->default('active');
            // Null when it was moved in from a product (source_reference).
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->string('source_reference', 100)->nullable();
            $table->foreignUuid('closed_by')->nullable()->constrained('users');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
            $table->unique(['organization_id', 'source_reference']);
        });

        Schema::create('petty_cash_vouchers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('fund_id')->constrained('petty_cash_funds');
            $table->string('number', 32);
            $table->date('paid_on');
            // In the fund's currency.
            $table->bigInteger('amount_minor');
            $table->string('payee')->nullable();
            $table->string('description');
            $table->foreignUuid('account_id')->constrained('accounts');
            $table->foreignUuid('cost_centre_id')->nullable()->constrained('dimension_values');
            $table->foreignUuid('project_id')->nullable()->constrained('dimension_values');
            $table->string('receipt_name');
            $table->string('receipt_path');
            $table->string('receipt_mime', 100);
            $table->unsignedBigInteger('receipt_size');
            $table->string('status', 16);
            $table->foreignUuid('journal_id')->constrained('journals');
            $table->foreignUuid('reversal_journal_id')->nullable()->constrained('journals');
            $table->foreignUuid('recorded_by')->constrained('users');
            $table->foreignUuid('checked_by')->nullable()->constrained('users');
            $table->timestamp('checked_at')->nullable();
            $table->string('reject_note')->nullable();
            // The payment request that brought the fund back up after it.
            $table->foreignUuid('replenished_by_id')->nullable()->constrained('payment_requests');
            $table->timestamps();

            $table->unique(['organization_id', 'number']);
            $table->index(['fund_id', 'status']);
        });

        Schema::create('petty_cash_counts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('fund_id')->constrained('petty_cash_funds');
            $table->date('counted_on');
            $table->bigInteger('counted_minor');
            $table->bigInteger('book_minor');
            $table->bigInteger('difference_minor');
            $table->string('note')->nullable();
            $table->foreignUuid('journal_id')->nullable()->constrained('journals');
            $table->foreignUuid('counted_by')->constrained('users');
            $table->timestamp('created_at')->nullable();

            $table->index(['fund_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('petty_cash_counts');
        Schema::dropIfExists('petty_cash_vouchers');
        Schema::dropIfExists('petty_cash_funds');
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('petty_cash_max_minor');
        });
    }
};
