<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Running numbers per organization (JV-000001, BILL-00001, ...).
        Schema::create('document_numbers', function (Blueprint $table) {
            $table->uuid('organization_id');
            $table->string('type', 32);
            $table->unsignedBigInteger('last_number');

            $table->primary(['organization_id', 'type']);
        });

        // The chart of accounts. Accounts the posting rules rely on carry a
        // system key (receivable, payable, sales, ...) and cannot be removed.
        Schema::create('accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->string('code', 16);
            $table->string('name');
            // asset, liability, equity, revenue, expense
            $table->string('type', 16);
            // Banks and cash: cash, bank, mobile_money, card; else null.
            $table->string('bank_kind', 16)->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account_number', 64)->nullable();
            $table->string('system_key', 32)->nullable();
            $table->string('description', 255)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
            $table->unique(['organization_id', 'system_key']);
        });

        // Journals: balanced, posted, never edited. A mistake is reversed by
        // another journal.
        Schema::create('journals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('number', 32);
            $table->date('entry_date');
            $table->string('memo', 255);
            // Who caused it: "finance" for entries made here, else the product.
            $table->string('source_app', 32);
            // What caused it: manual, supplier_payment, finance.invoice.issued, ...
            $table->string('source_type', 64);
            $table->string('source_reference', 128)->nullable();
            $table->uuid('branch_id')->nullable();
            $table->uuid('reversal_of_id')->nullable();
            $table->uuid('reversed_by_id')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'entry_date']);
        });
        // Self-references once the primary key exists.
        Schema::table('journals', function (Blueprint $table) {
            $table->foreign('reversal_of_id')->references('id')->on('journals');
            $table->foreign('reversed_by_id')->references('id')->on('journals');
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('journal_id')->constrained();
            $table->foreignUuid('account_id')->constrained();
            $table->unsignedSmallInteger('position');
            // Money in the currency's minor unit; exactly one side per line.
            $table->bigInteger('debit_minor')->default(0);
            $table->bigInteger('credit_minor')->default(0);
            $table->string('description', 255)->nullable();

            $table->index(['organization_id', 'account_id']);
        });

        // Payables.
        Schema::create('suppliers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->string('name');
            // Where the supplier came from: a product's own supplier id.
            $table->string('source_app', 32)->nullable();
            $table->string('source_supplier_id', 64)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'source_app', 'source_supplier_id']);
        });

        Schema::create('bills', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('supplier_id')->constrained();
            $table->string('number', 64);
            $table->string('supplier_reference', 64)->nullable();
            $table->string('source_app', 32);
            $table->uuid('branch_id')->nullable();
            $table->date('bill_date');
            $table->date('due_on')->nullable();
            $table->bigInteger('total_minor');
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('paid_minor')->default(0);
            $table->json('lines');
            $table->string('memo', 255)->nullable();
            // None for a zero total.
            $table->foreignUuid('journal_id')->nullable()->constrained();
            $table->timestamps();

            $table->unique(['organization_id', 'source_app', 'number']);
            $table->index(['organization_id', 'supplier_id']);
        });

        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('number', 32);
            $table->foreignUuid('supplier_id')->constrained();
            $table->date('paid_on');
            $table->bigInteger('amount_minor');
            // The bank or cash account it was paid from.
            $table->foreignUuid('account_id')->constrained();
            $table->string('reference', 64)->nullable();
            $table->string('memo', 255)->nullable();
            $table->foreignUuid('journal_id')->constrained();
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();

            $table->unique(['organization_id', 'number']);
        });

        Schema::create('bill_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('bill_id')->constrained();
            $table->foreignUuid('supplier_payment_id')->constrained();
            $table->bigInteger('amount_minor');
        });

        // Receivables.
        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('number', 64);
            $table->string('source_app', 32);
            $table->string('customer_name')->nullable();
            $table->uuid('branch_id')->nullable();
            $table->date('issued_on');
            $table->date('due_on')->nullable();
            $table->bigInteger('total_minor');
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('paid_minor')->default(0);
            $table->json('lines');
            // None for a zero total.
            $table->foreignUuid('journal_id')->nullable()->constrained();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->foreignUuid('void_journal_id')->nullable()->constrained('journals');
            $table->timestamps();

            $table->unique(['organization_id', 'source_app', 'number']);
            $table->index(['organization_id', 'issued_on']);
        });

        // Money received from customers (receipt) or given back (refund).
        Schema::create('customer_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('number', 64);
            $table->string('source_app', 32);
            $table->string('kind', 8);
            // The invoice it settles; the number alone while the invoice has
            // not arrived yet (events can come out of order).
            $table->foreignUuid('invoice_id')->nullable()->constrained();
            $table->string('invoice_number', 64)->nullable();
            $table->string('method', 16);
            $table->bigInteger('amount_minor');
            $table->date('received_on');
            $table->foreignUuid('account_id')->constrained();
            $table->string('processor_reference', 64)->nullable();
            $table->string('memo', 255)->nullable();
            $table->foreignUuid('journal_id')->constrained();
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['organization_id', 'source_app', 'number']);
            $table->index(['organization_id', 'source_app', 'invoice_number']);
        });

        // Business events from products, applied once each
        // (packages/shared-module-kit/schema/postgres.sql).
        Schema::create('shared_module_inbox', function (Blueprint $table) {
            $table->uuid('event_id')->primary();
            $table->string('source_app', 32);
            $table->uuid('organization_id');
            $table->string('source_transaction_id', 128);
            $table->string('event_type', 100);
            $table->smallInteger('schema_version');
            $table->char('content_hash', 64);
            $table->json('payload');
            $table->timestampTz('occurred_at');
            $table->timestampTz('received_at')->useCurrent();
            $table->timestampTz('processed_at')->nullable();

            $table->unique(['source_app', 'organization_id', 'source_transaction_id'], 'shared_module_inbox_source_transaction_unique');
            $table->index(['organization_id', 'received_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            // The database itself keeps the books straight: one side per
            // line, lines never change, and every journal balances when its
            // transaction commits.
            DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_one_side CHECK (debit_minor >= 0 AND credit_minor >= 0 AND (debit_minor = 0) <> (credit_minor = 0))');
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION journal_lines_append_only() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'journal_lines is append-only';
                END;
                $$ LANGUAGE plpgsql
            SQL);
            DB::statement('CREATE TRIGGER journal_lines_append_only BEFORE UPDATE OR DELETE ON journal_lines FOR EACH ROW EXECUTE FUNCTION journal_lines_append_only()');
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION journal_lines_balanced() RETURNS trigger AS $$
                BEGIN
                    IF (SELECT COALESCE(SUM(debit_minor - credit_minor), 0) FROM journal_lines WHERE journal_id = NEW.journal_id) <> 0 THEN
                        RAISE EXCEPTION 'journal % does not balance', NEW.journal_id;
                    END IF;
                    RETURN NULL;
                END;
                $$ LANGUAGE plpgsql
            SQL);
            DB::statement('CREATE CONSTRAINT TRIGGER journal_lines_balanced AFTER INSERT ON journal_lines DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION journal_lines_balanced()');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS journal_lines_balanced ON journal_lines');
            DB::statement('DROP TRIGGER IF EXISTS journal_lines_append_only ON journal_lines');
            DB::statement('DROP FUNCTION IF EXISTS journal_lines_balanced()');
            DB::statement('DROP FUNCTION IF EXISTS journal_lines_append_only()');
        }

        Schema::dropIfExists('shared_module_inbox');
        Schema::dropIfExists('customer_payments');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('bill_payments');
        Schema::dropIfExists('supplier_payments');
        Schema::dropIfExists('bills');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journals');
        Schema::dropIfExists('accounts');
        Schema::dropIfExists('document_numbers');
    }
};
