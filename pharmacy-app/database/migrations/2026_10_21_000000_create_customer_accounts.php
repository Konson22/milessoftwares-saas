<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer accounts (Retail R3): customers of an industry module, sales on
 * account against a credit limit, due after the customer's payment terms,
 * and payments taken against them, each shared over the oldest sales still
 * owed (allocations).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->string('application', 32);
            $table->string('number', 16);
            $table->string('name');
            $table->string('phone', 64)->nullable();
            $table->string('email')->nullable();
            $table->string('address', 500)->nullable();
            // 0: may not buy on account.
            $table->unsignedBigInteger('credit_limit_minor')->default(0);
            $table->unsignedSmallInteger('payment_terms_days')->default(30);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'number']);
            $table->unique(['organization_id', 'application', 'name']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->uuid('customer_id')->nullable()->after('customer_name');
            // Sales on account: when the customer is to have paid.
            $table->date('due_on')->nullable()->after('customer_id');
            $table->index(['organization_id', 'customer_id']);
        });

        Schema::create('customer_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->string('application', 32);
            $table->uuid('branch_id');
            $table->uuid('customer_id');
            $table->string('number', 16);
            $table->string('method', 16);
            $table->string('reference', 128)->nullable();
            $table->unsignedBigInteger('amount_minor');
            $table->string('note')->nullable();
            // Cash goes into the till of whoever takes it.
            $table->uuid('till_session_id')->nullable();
            $table->uuid('received_by');
            $table->timestamp('received_at');
            $table->uuid('submission_id')->nullable()->unique();
            $table->timestamps();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'customer_id']);
            $table->index('till_session_id');
        });

        Schema::create('customer_payment_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->uuid('customer_payment_id')->index();
            $table->uuid('sale_id')->index();
            $table->unsignedBigInteger('amount_minor');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_payment_allocations');
        Schema::dropIfExists('customer_payments');

        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'customer_id']);
            $table->dropColumn(['customer_id', 'due_on']);
        });

        Schema::dropIfExists('customers');
    }
};
