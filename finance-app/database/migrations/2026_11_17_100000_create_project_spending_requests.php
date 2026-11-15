<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spending Projects hands to Finance (finance.spending.submitted, Phase 3C):
 * one row per request revision, waiting for a Finance person to take it in
 * as a payment request (App\Finance\ProjectSpending) or refuse it. Finance's
 * own approval, disbursement and ledger rules then apply unchanged; the row
 * keeps the Projects references and the sequence of outcomes sent back.
 *
 * Additive only; down() drops it while nothing depends on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_spending_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            // Projects' references (docs/finance/finance-projects-contract.md §3).
            $table->uuid('request_id');
            $table->uuid('correlation_id');
            $table->string('idempotency_key', 128);
            $table->unsignedInteger('revision');
            $table->uuid('project_id');
            $table->string('project_code', 32);
            $table->string('project_name');
            $table->uuid('expense_id');
            $table->string('category', 32);
            $table->string('description');
            $table->string('supplier')->nullable();
            $table->string('grant_reference', 64)->nullable();
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->timestampTz('requested_at');
            // Finance's side.
            $table->string('status', 24);
            $table->foreignUuid('payment_request_id')->nullable()->constrained('payment_requests');
            $table->foreignUuid('decided_by')->nullable()->constrained('users');
            $table->timestampTz('decided_at')->nullable();
            $table->string('decision_note')->nullable();
            $table->timestampTz('cancellation_requested_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            // The last outcome number sent to Projects for this request.
            $table->unsignedInteger('sequence')->default(0);
            $table->timestampsTz();

            $table->unique(['organization_id', 'idempotency_key']);
            $table->unique(['organization_id', 'request_id']);
            $table->unique(['organization_id', 'correlation_id', 'revision']);
            $table->unique(['payment_request_id']);
            $table->index(['organization_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE project_spending_requests ADD CONSTRAINT project_spending_requests_status CHECK (status IN ('received', 'prepared', 'approved', 'paid', 'reversed', 'rejected', 'cancelled'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('project_spending_requests');
    }
};
