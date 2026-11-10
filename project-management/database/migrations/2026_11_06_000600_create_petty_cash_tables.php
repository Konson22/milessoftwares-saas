<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Petty cash as a fixed float. Each box has its own cash account in the
 * ledger, and its balance is read from there. The organization sets the
 * largest payment, on its books so that it stays in the books' currency.
 */
return new class extends Migration
{
    /** child => column => parent. */
    private const KEYS = [
        'petty_cash_funds' => ['account_id' => 'accounts', 'journal_id' => 'journals', 'closing_journal_id' => 'journals'],
        'petty_cash_top_ups' => ['fund_id' => 'petty_cash_funds', 'source_account_id' => 'accounts', 'journal_id' => 'journals'],
        'petty_cash_payments' => ['fund_id' => 'petty_cash_funds', 'account_id' => 'accounts', 'project_id' => 'projects', 'top_up_id' => 'petty_cash_top_ups', 'journal_id' => 'journals', 'void_journal_id' => 'journals'],
        'petty_cash_counts' => ['fund_id' => 'petty_cash_funds', 'journal_id' => 'journals'],
    ];

    public function up(): void
    {
        Schema::table('accounting_books', function (Blueprint $table) {
            $table->bigInteger('petty_cash_max_payment_minor')->nullable();
        });

        Schema::create('petty_cash_funds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->foreignUuid('account_id')->constrained();
            $table->foreignUuid('custodian_id')->constrained('users');
            $table->bigInteger('float_minor');
            $table->string('currency', 3);
            $table->string('status', 20)->default('active');
            $table->foreignUuid('journal_id')->nullable()->constrained();
            $table->foreignUuid('closing_journal_id')->nullable()->constrained('journals');
            $table->timestamp('closed_at')->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
            $table->unique(['organization_id', 'id'], 'petty_cash_funds_tenant_key');
            $table->unique('account_id');
        });

        Schema::create('petty_cash_top_ups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('fund_id')->constrained('petty_cash_funds');
            $table->bigInteger('amount_minor');
            $table->string('status', 20)->default('submitted');
            $table->foreignUuid('requested_by')->constrained('users');
            $table->foreignUuid('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignUuid('source_account_id')->nullable()->constrained('accounts');
            $table->foreignUuid('journal_id')->nullable()->constrained();
            $table->timestamps();

            $table->unique(['organization_id', 'id'], 'petty_cash_top_ups_tenant_key');
            $table->index(['fund_id', 'status']);
        });

        Schema::create('petty_cash_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('fund_id')->constrained('petty_cash_funds');
            $table->date('paid_on');
            $table->bigInteger('amount_minor');
            $table->string('description');
            $table->string('payee')->nullable();
            // What it was spent on: an expense account, or a project's category.
            $table->foreignUuid('account_id')->nullable()->constrained();
            $table->foreignUuid('project_id')->nullable()->constrained();
            $table->foreignUuid('expense_id')->nullable()->constrained();
            $table->string('receipt_name');
            $table->string('receipt_path');
            $table->string('receipt_mime');
            $table->unsignedBigInteger('receipt_size');
            $table->foreignUuid('top_up_id')->nullable()->constrained('petty_cash_top_ups');
            $table->foreignUuid('journal_id')->nullable()->constrained();
            $table->foreignUuid('void_journal_id')->nullable()->constrained('journals');
            $table->timestamp('voided_at')->nullable();
            $table->foreignUuid('voided_by')->nullable()->constrained('users');
            $table->text('void_reason')->nullable();
            $table->foreignUuid('recorded_by')->constrained('users');
            $table->timestamps();

            $table->index(['fund_id', 'paid_on']);
            $table->index(['fund_id', 'top_up_id']);
        });

        Schema::create('petty_cash_counts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('fund_id')->constrained('petty_cash_funds');
            $table->date('counted_on');
            $table->bigInteger('counted_minor');
            $table->bigInteger('book_minor');
            $table->bigInteger('difference_minor');
            $table->text('note')->nullable();
            $table->foreignUuid('journal_id')->nullable()->constrained();
            $table->foreignUuid('counted_by')->constrained('users');
            $table->timestamps();

            $table->index(['fund_id', 'counted_on']);
        });

        if (DB::getDriverName() === 'pgsql') {
            foreach (self::KEYS as $child => $columns) {
                Schema::table($child, function (Blueprint $table) use ($child, $columns) {
                    foreach ($columns as $column => $parent) {
                        $table->foreign(['organization_id', $column], "{$child}_{$column}_tenant_fk")->references(['organization_id', 'id'])->on($parent);
                    }
                });
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('petty_cash_counts');
        Schema::dropIfExists('petty_cash_payments');
        Schema::dropIfExists('petty_cash_top_ups');
        Schema::dropIfExists('petty_cash_funds');

        Schema::table('accounting_books', function (Blueprint $table) {
            $table->dropColumn('petty_cash_max_payment_minor');
        });
    }
};
