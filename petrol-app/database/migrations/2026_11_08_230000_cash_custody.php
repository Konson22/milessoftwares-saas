<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('safes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->string('name');
            $table->string('code', 32);
            $table->uuid('custodian_user_id')->nullable();
            $table->string('status', 16)->default('open');
            $table->timestamps();
            $table->unique(['organization_id', 'branch_id', 'code']);
        });

        Schema::create('cash_transfers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->string('number', 32);
            $table->string('kind', 32);
            $table->string('source_type', 16);
            $table->uuid('source_id');
            $table->string('destination_type', 16);
            $table->uuid('destination_id')->nullable();
            $table->uuid('shift_id')->nullable();
            $table->char('currency', 3)->nullable();
            $table->bigInteger('declared_minor');
            $table->bigInteger('counted_minor')->nullable();
            $table->char('fx_base', 3)->nullable();
            $table->char('fx_quote', 3)->nullable();
            $table->unsignedBigInteger('fx_rate_minor')->nullable();
            $table->bigInteger('reporting_minor')->nullable();
            $table->string('status', 16);
            $table->string('idempotency_key', 64)->nullable();
            $table->uuid('initiated_by');
            $table->uuid('confirmed_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'number']);
            $table->unique(['organization_id', 'idempotency_key']);
        });

        Schema::create('bank_deposits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->uuid('safe_id');
            $table->string('number', 32);
            $table->string('bank_reference');
            $table->char('currency', 3)->nullable();
            $table->bigInteger('amount_minor');
            $table->bigInteger('reporting_minor')->nullable();
            $table->char('fx_base', 3)->nullable();
            $table->char('fx_quote', 3)->nullable();
            $table->unsignedBigInteger('fx_rate_minor')->nullable();
            $table->date('deposit_on');
            $table->string('status', 16);
            $table->string('slip_reference')->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->uuid('prepared_by');
            $table->uuid('confirmed_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'number']);
            $table->unique(['organization_id', 'idempotency_key']);
        });

        Schema::create('cash_counts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->string('holder_type', 16);
            $table->uuid('holder_id');
            $table->uuid('shift_id')->nullable();
            $table->char('currency', 3)->nullable();
            $table->bigInteger('expected_minor');
            $table->bigInteger('counted_minor');
            $table->bigInteger('difference_minor');
            $table->uuid('counted_by');
            $table->timestamp('counted_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_counts');
        Schema::dropIfExists('bank_deposits');
        Schema::dropIfExists('cash_transfers');
        Schema::dropIfExists('safes');
    }
};
