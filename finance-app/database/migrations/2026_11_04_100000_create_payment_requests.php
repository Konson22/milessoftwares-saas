<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The organization's payment approval rules. Off by default, so
        // direct payments keep working until an organization turns this on.
        Schema::table('workspaces', function (Blueprint $table) {
            $table->boolean('payment_approval')->default(false);
            $table->bigInteger('payment_approval_threshold_minor')->nullable();
            $table->boolean('payment_review')->default(false);
            $table->boolean('payment_separation')->default(true);
        });

        // A recurring journal that takes money out of a bank needs a payment
        // approver's approval, which a journal approver's does not stand in for.
        Schema::table('recurring_journals', function (Blueprint $table) {
            $table->boolean('approved_for_payment')->default(false);
        });

        // Money waiting to leave: not in the ledger until it is disbursed,
        // when it posts through Payables or Banking like any other payment.
        Schema::create('payment_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('number', 32);
            $table->string('kind', 16);
            $table->string('status', 20);
            $table->string('description');
            $table->string('payee')->nullable();
            $table->foreignUuid('account_id')->constrained('accounts');
            $table->foreignUuid('other_account_id')->nullable()->constrained('accounts');
            $table->foreignUuid('supplier_id')->nullable()->constrained('suppliers');
            $table->foreignUuid('cost_centre_id')->nullable()->constrained('dimension_values');
            $table->foreignUuid('project_id')->nullable()->constrained('dimension_values');
            $table->bigInteger('amount_minor');
            $table->string('currency', 3)->nullable();
            $table->bigInteger('books_minor');
            $table->date('pay_on');
            // {allocations: {bill id: minor}, reference, rate, received_minor},
            // or what a held item posts: {tax_return_id}, {lines, draft}, {journal_id, memo}
            $table->json('details')->nullable();
            $table->foreignUuid('prepared_by')->constrained('users');
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignUuid('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->foreignUuid('disbursed_by')->nullable()->constrained('users');
            $table->timestamp('disbursed_at')->nullable();
            $table->foreignUuid('closed_by')->nullable()->constrained('users');
            $table->timestamp('closed_at')->nullable();
            $table->string('close_note')->nullable();
            $table->foreignUuid('journal_id')->nullable()->constrained('journals');
            $table->foreignUuid('supplier_payment_id')->nullable()->constrained('supplier_payments');
            $table->timestamps();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_requests');
        Schema::table('recurring_journals', function (Blueprint $table) {
            $table->dropColumn('approved_for_payment');
        });
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn(['payment_approval', 'payment_approval_threshold_minor', 'payment_review', 'payment_separation']);
        });
    }
};
