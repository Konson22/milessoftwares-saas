<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finance integration infrastructure (Phase 3B, ADR-0017 §6). Additive only:
 * no existing row, column or payment path changes meaning.
 *
 * - organizations.finance_mode: legacy for everyone. Nothing switches it
 *   but the projects:finance-mode command, with a reason, after checks.
 * - finance_mode_changes: every switch, who, why, and the checks then.
 * - shared_module_inbox: Finance's outcomes, applied exactly once (the
 *   shared module kit's table, as in Finance).
 * - finance_inbox_rejections: events refused, kept for reconciliation.
 * - finance_requests: spending handed to Finance (integrated mode only),
 *   with stable references; finance_request_transitions their append-only
 *   history; finance_payments what Finance says it paid or reversed.
 *
 * Reversible while unused: down() drops it all. Once an organization is
 * integrated, rolling back would lose Finance's outcomes: switch it to
 * suspended instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('finance_mode', 32)->default('legacy');
            $table->timestampTz('finance_mode_changed_at')->nullable();
        });

        Schema::create('finance_mode_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('from_mode', 32);
            $table->string('to_mode', 32);
            $table->text('reason');
            $table->json('checks');
            $table->string('changed_by', 191);
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['organization_id', 'created_at']);
        });

        if (! Schema::hasTable('shared_module_inbox')) {
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
        }

        Schema::create('finance_inbox_rejections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Null when the sender could not be identified (bad token, malformed body).
            $table->uuid('organization_id')->nullable();
            $table->string('source_app', 32)->nullable();
            $table->string('event_id', 64)->nullable();
            $table->string('event_type', 100)->nullable();
            $table->string('source_transaction_id', 128)->nullable();
            $table->smallInteger('http_status');
            $table->string('reason', 64);
            $table->text('detail')->nullable();
            $table->json('payload')->nullable();
            $table->timestampTz('received_at')->useCurrent();
            $table->index(['organization_id', 'received_at']);
            $table->index(['reason', 'received_at']);
        });

        Schema::create('finance_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('project_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('expense_id')->constrained()->restrictOnDelete();
            $table->string('kind', 32);
            $table->unsignedInteger('revision')->default(1);
            $table->uuid('correlation_id')->unique();
            $table->string('idempotency_key', 128)->unique();
            $table->unsignedSmallInteger('contract_version');
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->string('status', 32);
            $table->string('finance_reference', 100)->nullable();
            $table->unsignedBigInteger('approved_minor')->nullable();
            $table->unsignedBigInteger('paid_minor')->default(0);
            $table->unsignedBigInteger('reversed_minor')->default(0);
            // The last Finance sequence number applied: outcomes apply in order.
            $table->unsignedInteger('finance_sequence')->default(0);
            $table->timestampTz('submitted_at');
            $table->timestampTz('last_event_at')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'expense_id', 'revision']);
            $table->unique(['organization_id', 'finance_reference']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('finance_request_transitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('finance_request_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->string('cause', 100);
            $table->string('source', 16);
            $table->string('event_id', 64)->nullable();
            $table->unsignedInteger('finance_sequence')->nullable();
            $table->json('facts');
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['finance_request_id', 'created_at']);
        });

        Schema::create('finance_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('finance_request_id')->constrained()->restrictOnDelete();
            $table->string('finance_payment_id', 100);
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('reversed_minor')->default(0);
            $table->date('paid_on');
            $table->string('status', 16);
            $table->string('journal_reference', 100)->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'finance_payment_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            // History is kept as it happened: transitions are never edited or removed.
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION finance_request_transitions_append_only() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'finance_request_transitions is append-only';
                END;
                $$ LANGUAGE plpgsql
                SQL);
            DB::statement('CREATE TRIGGER finance_request_transitions_append_only BEFORE UPDATE OR DELETE ON finance_request_transitions FOR EACH ROW WHEN (pg_trigger_depth() = 0) EXECUTE FUNCTION finance_request_transitions_append_only()');
            DB::statement('ALTER TABLE finance_requests ADD CONSTRAINT finance_requests_paid_within CHECK (paid_minor <= amount_minor AND reversed_minor <= paid_minor)');
            DB::statement('ALTER TABLE finance_payments ADD CONSTRAINT finance_payments_reversed_within CHECK (reversed_minor <= amount_minor)');
            DB::statement("ALTER TABLE organizations ADD CONSTRAINT organizations_finance_mode CHECK (finance_mode IN ('legacy', 'reconciliation_required', 'ready', 'integrated', 'suspended'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE organizations DROP CONSTRAINT IF EXISTS organizations_finance_mode');
        }
        Schema::dropIfExists('finance_payments');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS finance_request_transitions_append_only ON finance_request_transitions');
            DB::statement('DROP FUNCTION IF EXISTS finance_request_transitions_append_only()');
        }
        Schema::dropIfExists('finance_request_transitions');
        Schema::dropIfExists('finance_requests');
        Schema::dropIfExists('finance_inbox_rejections');
        Schema::dropIfExists('shared_module_inbox');
        Schema::dropIfExists('finance_mode_changes');
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['finance_mode', 'finance_mode_changed_at']);
        });
    }
};
