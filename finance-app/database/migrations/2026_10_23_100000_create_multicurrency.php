<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rates typed by the organization: books-currency units for one unit
        // of the currency, from that day until the next rate.
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->char('currency', 3);
            $table->date('rate_on');
            $table->decimal('rate', 24, 10);
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['organization_id', 'currency', 'rate_on']);
        });

        // A bank or cash account held in another currency (null: the books').
        Schema::table('accounts', function (Blueprint $table) {
            $table->char('currency', 3)->nullable();
        });

        // A line in another currency keeps that amount too (signed: positive
        // on the debit side). Debit and credit stay in the books' currency.
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->char('currency', 3)->nullable();
            $table->bigInteger('currency_minor')->nullable();
        });

        // Documents and payments in another currency: the amounts in it and
        // the rate used. The *_minor amounts stay in the books' currency.
        foreach (['invoices', 'bills'] as $documents) {
            Schema::table($documents, function (Blueprint $table) {
                $table->char('currency', 3)->nullable();
                $table->decimal('exchange_rate', 24, 10)->nullable();
                $table->bigInteger('currency_total_minor')->nullable();
                $table->bigInteger('currency_paid_minor')->default(0);
            });
        }
        foreach (['customer_payments', 'supplier_payments'] as $payments) {
            Schema::table($payments, function (Blueprint $table) {
                $table->char('currency', 3)->nullable();
                $table->decimal('exchange_rate', 24, 10)->nullable();
                $table->bigInteger('currency_amount_minor')->nullable();
                // Realised exchange gain (positive) or loss, in the books' currency.
                $table->bigInteger('fx_minor')->default(0);
            });
        }
        Schema::table('bill_payments', function (Blueprint $table) {
            $table->bigInteger('currency_amount_minor')->nullable();
        });

        // Month-end revaluations: a journal on the day, reversed the next.
        Schema::create('revaluations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->date('as_of');
            $table->foreignUuid('journal_id')->nullable()->constrained('journals');
            $table->foreignUuid('reversal_journal_id')->nullable()->constrained('journals');
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();

            $table->unique(['organization_id', 'as_of']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revaluations');
        Schema::table('bill_payments', fn (Blueprint $table) => $table->dropColumn('currency_amount_minor'));
        foreach (['customer_payments', 'supplier_payments'] as $payments) {
            Schema::table($payments, fn (Blueprint $table) => $table->dropColumn(['currency', 'exchange_rate', 'currency_amount_minor', 'fx_minor']));
        }
        foreach (['invoices', 'bills'] as $documents) {
            Schema::table($documents, fn (Blueprint $table) => $table->dropColumn(['currency', 'exchange_rate', 'currency_total_minor', 'currency_paid_minor']));
        }
        Schema::table('journal_lines', fn (Blueprint $table) => $table->dropColumn(['currency', 'currency_minor']));
        Schema::table('accounts', fn (Blueprint $table) => $table->dropColumn('currency'));
        Schema::dropIfExists('exchange_rates');
    }
};
