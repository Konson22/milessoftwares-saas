<?php

use App\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tatook Procurement (ADR-0023): plans, requisitions, approvals, suppliers,
 * sourcing (one engine for every method), quotations, evaluation, awards,
 * purchase orders, receipts and supplier invoices.
 *
 * Every table is tenant keyed: a child points at its parent through
 * (organization_id, id), so no row can reference another organization's.
 * Money: each document keeps its own currency, the rate used (workspace
 * minor units for one unit of it) and its total in the workspace currency.
 * Quantities are exact decimals.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('type', 16);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last');
            $table->timestamps();
            $table->unique(['organization_id', 'type', 'year']);
        });

        Schema::create('procurement_audit_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('action', 48);
            $table->string('subject_type', 32);
            $table->uuid('subject_id')->nullable();
            $table->string('subject_label');
            $table->foreignUuid('user_id')->nullable()->constrained('users');
            $table->string('user_name')->nullable();
            $table->json('details')->nullable();
            $table->timestampTz('created_at');
            $table->index(['organization_id', 'created_at']);
            $table->index(['organization_id', 'subject_type', 'subject_id']);
        });

        Schema::create('procurement_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('subject_type', 32);
            $table->uuid('subject_id');
            $table->string('name');
            $table->string('path');
            $table->unsignedInteger('size');
            $table->string('mime', 128)->nullable();
            $table->foreignUuid('uploaded_by')->constrained('users');
            $table->timestamps();
            $table->index(['organization_id', 'subject_type', 'subject_id']);
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('name');
            // Lowercase, punctuation and company suffixes removed: duplicates are refused on it.
            $table->string('name_key');
            $table->string('type', 16);
            $table->string('contact_person')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->text('address')->nullable();
            $table->char('country', 2)->nullable();
            $table->string('registration_number', 64)->nullable();
            $table->string('tax_number', 64)->nullable();
            $table->json('categories')->nullable();
            $table->string('status', 16)->default('active');
            $table->text('status_reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();
            TenantSchema::key($table);
            $table->unique(['organization_id', 'name_key']);
            $table->unique(['organization_id', 'tax_number']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('approval_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('document_type', 16);
            $table->string('name');
            $table->bigInteger('min_base_minor')->default(0);
            $table->bigInteger('max_base_minor')->nullable();
            $table->uuid('department_id')->nullable();
            $table->string('department_name')->nullable();
            $table->string('method', 16)->nullable();
            // [{type: "permission"|"member", value, label}], in order.
            $table->json('steps');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['organization_id', 'document_type', 'active']);
        });

        Schema::create('approvals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('document_type', 16);
            $table->uuid('document_id');
            // A document submitted again after being returned or amended starts a new round.
            $table->unsignedSmallInteger('round');
            $table->unsignedSmallInteger('step');
            $table->uuid('rule_id')->nullable();
            $table->string('approver_type', 16);
            $table->string('approver_value');
            $table->string('approver_label');
            $table->foreignUuid('submitted_by')->constrained('users');
            $table->string('status', 16);
            $table->foreignUuid('decided_by')->nullable()->constrained('users');
            $table->string('decided_by_name')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'document_type', 'document_id', 'round', 'step']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('procurement_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('number', 24);
            $table->string('title');
            $table->string('fiscal_year', 16);
            $table->uuid('department_id')->nullable();
            $table->string('department_name')->nullable();
            $table->string('status', 24);
            $table->unsignedSmallInteger('revision')->default(1);
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestamps();
            TenantSchema::key($table);
            $table->unique(['organization_id', 'number']);
        });

        Schema::create('procurement_plan_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('plan_id');
            $table->string('description');
            $table->string('category', 64)->nullable();
            $table->string('method', 16);
            $table->decimal('quantity', 14, 3);
            $table->string('unit', 32)->nullable();
            $table->bigInteger('estimated_base_minor');
            $table->date('expected_on')->nullable();
            $table->string('funding_reference', 120)->nullable();
            $table->string('project_reference', 120)->nullable();
            $table->foreignUuid('responsible_user_id')->nullable()->constrained('users');
            $table->timestamps();
            TenantSchema::key($table);
            TenantSchema::foreign($table, 'plan_id', 'procurement_plans');
        });

        Schema::create('requisitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('number', 24);
            $table->foreignUuid('requester_id')->constrained('users');
            $table->uuid('department_id')->nullable();
            $table->string('department_name')->nullable();
            $table->uuid('plan_item_id')->nullable();
            $table->string('title');
            $table->text('justification');
            $table->date('needed_by')->nullable();
            $table->string('delivery_location')->nullable();
            $table->char('currency', 3);
            $table->bigInteger('rate_minor')->nullable();
            $table->bigInteger('total_minor');
            $table->bigInteger('total_base_minor');
            $table->string('budget_reference', 120)->nullable();
            $table->string('project_reference', 120)->nullable();
            $table->string('funding_reference', 120)->nullable();
            $table->string('status', 24);
            $table->unsignedSmallInteger('revision')->default(1);
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->text('status_reason')->nullable();
            $table->timestamps();
            TenantSchema::key($table);
            TenantSchema::foreign($table, 'plan_item_id', 'procurement_plan_items');
            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'requester_id']);
        });

        Schema::create('requisition_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('requisition_id');
            $table->unsignedSmallInteger('line_no');
            $table->string('kind', 8);
            $table->string('description');
            $table->text('specification')->nullable();
            $table->decimal('quantity', 14, 3);
            $table->string('unit', 32)->nullable();
            $table->bigInteger('unit_price_minor');
            $table->bigInteger('amount_minor');
            $table->timestamps();
            TenantSchema::key($table);
            TenantSchema::foreign($table, 'requisition_id', 'requisitions');
        });

        Schema::create('sourcing_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('number', 24);
            $table->uuid('requisition_id');
            $table->string('method', 16);
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('delivery_location')->nullable();
            $table->timestampTz('quotes_due_at');
            $table->date('required_by')->nullable();
            // Prices stay hidden until the event is closed.
            $table->boolean('sealed')->default(false);
            $table->text('method_reason')->nullable();
            $table->string('status', 24);
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestampTz('issued_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->text('status_reason')->nullable();
            $table->timestamps();
            TenantSchema::key($table);
            TenantSchema::foreign($table, 'requisition_id', 'requisitions');
            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('sourcing_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('sourcing_event_id');
            $table->uuid('requisition_line_id')->nullable();
            $table->unsignedSmallInteger('line_no');
            $table->string('kind', 8);
            $table->string('description');
            $table->text('specification')->nullable();
            $table->decimal('quantity', 14, 3);
            $table->string('unit', 32)->nullable();
            $table->timestamps();
            TenantSchema::key($table);
            TenantSchema::foreign($table, 'sourcing_event_id', 'sourcing_events');
            TenantSchema::foreign($table, 'requisition_line_id', 'requisition_lines');
        });

        Schema::create('sourcing_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('sourcing_event_id');
            $table->uuid('supplier_id');
            $table->timestamps();
            TenantSchema::foreign($table, 'sourcing_event_id', 'sourcing_events');
            TenantSchema::foreign($table, 'supplier_id', 'suppliers');
            $table->unique(['sourcing_event_id', 'supplier_id']);
        });

        Schema::create('evaluation_criteria', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('sourcing_event_id');
            $table->string('name');
            // pass_fail or score.
            $table->string('kind', 16);
            $table->boolean('mandatory')->default(false);
            $table->unsignedSmallInteger('max_score')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            TenantSchema::key($table);
            TenantSchema::foreign($table, 'sourcing_event_id', 'sourcing_events');
        });

        Schema::create('quotations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('sourcing_event_id');
            $table->uuid('supplier_id');
            $table->string('reference', 100)->nullable();
            $table->date('quoted_on');
            $table->timestampTz('received_at');
            $table->boolean('late')->default(false);
            $table->char('currency', 3);
            $table->bigInteger('rate_minor')->nullable();
            $table->bigInteger('subtotal_minor');
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('delivery_minor')->default(0);
            $table->bigInteger('total_minor');
            $table->bigInteger('total_base_minor');
            $table->unsignedSmallInteger('delivery_days')->nullable();
            $table->string('warranty')->nullable();
            $table->string('payment_terms')->nullable();
            $table->date('valid_until')->nullable();
            $table->string('status', 16)->default('received');
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();
            TenantSchema::key($table);
            TenantSchema::foreign($table, 'sourcing_event_id', 'sourcing_events');
            TenantSchema::foreign($table, 'supplier_id', 'suppliers');
            $table->unique(['sourcing_event_id', 'supplier_id']);
        });

        Schema::create('quotation_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('quotation_id');
            $table->uuid('sourcing_item_id');
            $table->decimal('quantity', 14, 3);
            $table->bigInteger('unit_price_minor');
            $table->bigInteger('amount_minor');
            $table->boolean('compliant')->default(true);
            $table->string('note')->nullable();
            $table->timestamps();
            TenantSchema::key($table);
            TenantSchema::foreign($table, 'quotation_id', 'quotations');
            TenantSchema::foreign($table, 'sourcing_item_id', 'sourcing_items');
            $table->unique(['quotation_id', 'sourcing_item_id']);
        });

        Schema::create('evaluation_scores', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('quotation_id');
            $table->uuid('criterion_id');
            $table->foreignUuid('evaluator_id')->constrained('users');
            $table->string('evaluator_name');
            $table->boolean('passed')->nullable();
            $table->decimal('score', 8, 2)->nullable();
            $table->string('note')->nullable();
            $table->timestamps();
            TenantSchema::foreign($table, 'quotation_id', 'quotations');
            TenantSchema::foreign($table, 'criterion_id', 'evaluation_criteria');
            $table->unique(['quotation_id', 'criterion_id', 'evaluator_id']);
        });

        Schema::create('awards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('sourcing_event_id');
            $table->uuid('quotation_id');
            $table->uuid('supplier_id');
            $table->foreignUuid('recommended_by')->constrained('users');
            $table->text('justification');
            // Why the lowest compliant offer was passed over, or fewer quotations than required.
            $table->text('deviation_reason')->nullable();
            $table->string('status', 24);
            $table->timestampTz('decided_at')->nullable();
            $table->timestamps();
            TenantSchema::key($table);
            TenantSchema::foreign($table, 'sourcing_event_id', 'sourcing_events');
            TenantSchema::foreign($table, 'quotation_id', 'quotations');
            TenantSchema::foreign($table, 'supplier_id', 'suppliers');
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('number', 24);
            $table->uuid('supplier_id');
            $table->uuid('requisition_id')->nullable();
            $table->uuid('sourcing_event_id')->nullable();
            $table->uuid('award_id')->nullable();
            $table->string('method', 16);
            $table->text('method_reason')->nullable();
            $table->uuid('department_id')->nullable();
            $table->string('department_name')->nullable();
            $table->char('currency', 3);
            $table->bigInteger('rate_minor')->nullable();
            $table->bigInteger('subtotal_minor');
            $table->bigInteger('discount_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('delivery_minor')->default(0);
            $table->bigInteger('total_minor');
            $table->bigInteger('total_base_minor');
            $table->date('delivery_date')->nullable();
            $table->text('delivery_address')->nullable();
            $table->string('payment_terms')->nullable();
            $table->text('notes')->nullable();
            $table->string('budget_reference', 120)->nullable();
            $table->string('project_reference', 120)->nullable();
            $table->string('funding_reference', 120)->nullable();
            $table->string('status', 24);
            $table->unsignedSmallInteger('revision')->default(1);
            $table->foreignUuid('prepared_by')->constrained('users');
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('issued_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->text('status_reason')->nullable();
            $table->timestamps();
            TenantSchema::key($table);
            TenantSchema::foreign($table, 'supplier_id', 'suppliers');
            TenantSchema::foreign($table, 'requisition_id', 'requisitions');
            TenantSchema::foreign($table, 'sourcing_event_id', 'sourcing_events');
            TenantSchema::foreign($table, 'award_id', 'awards');
            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'supplier_id']);
        });

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('purchase_order_id');
            $table->unsignedSmallInteger('line_no');
            $table->string('kind', 8);
            $table->string('description');
            $table->decimal('quantity', 14, 3);
            $table->string('unit', 32)->nullable();
            $table->bigInteger('unit_price_minor');
            $table->bigInteger('amount_minor');
            $table->uuid('requisition_line_id')->nullable();
            $table->timestamps();
            TenantSchema::key($table);
            TenantSchema::foreign($table, 'purchase_order_id', 'purchase_orders');
        });

        Schema::create('purchase_order_revisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('purchase_order_id');
            $table->unsignedSmallInteger('revision');
            $table->json('snapshot');
            $table->text('reason');
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();
            TenantSchema::foreign($table, 'purchase_order_id', 'purchase_orders');
            $table->unique(['purchase_order_id', 'revision']);
        });

        Schema::create('receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('number', 24);
            $table->uuid('purchase_order_id');
            $table->date('received_on');
            $table->string('delivery_note', 100)->nullable();
            // passed, partial or failed.
            $table->string('inspection', 16);
            $table->text('notes')->nullable();
            $table->foreignUuid('received_by')->constrained('users');
            $table->string('received_by_name');
            $table->timestamps();
            TenantSchema::key($table);
            TenantSchema::foreign($table, 'purchase_order_id', 'purchase_orders');
            $table->unique(['organization_id', 'number']);
        });

        Schema::create('receipt_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('receipt_id');
            $table->uuid('purchase_order_line_id');
            $table->decimal('received_quantity', 14, 3);
            $table->decimal('accepted_quantity', 14, 3);
            $table->decimal('rejected_quantity', 14, 3);
            $table->string('rejection_reason')->nullable();
            $table->timestamps();
            TenantSchema::key($table);
            TenantSchema::foreign($table, 'receipt_id', 'receipts');
            TenantSchema::foreign($table, 'purchase_order_line_id', 'purchase_order_lines');
        });

        Schema::create('supplier_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('purchase_order_id');
            $table->uuid('supplier_id');
            $table->string('number', 64);
            // Uppercase, without spaces or punctuation: the duplicate check.
            $table->string('number_key', 64);
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->char('currency', 3);
            $table->bigInteger('amount_minor');
            $table->bigInteger('amount_base_minor');
            // matched (within what was accepted) or awaiting_receipt.
            $table->string('match_status', 24);
            $table->string('status', 16)->default('recorded');
            $table->text('void_reason')->nullable();
            // unpaid or paid_externally (recorded by hand, never verified).
            $table->string('payment_status', 24)->default('unpaid');
            $table->date('paid_on')->nullable();
            $table->string('payment_reference', 100)->nullable();
            $table->foreignUuid('payment_recorded_by')->nullable()->constrained('users');
            // none or sent: Finance owns the liability and its payment once sent.
            $table->string('finance_status', 16)->default('none');
            $table->timestampTz('finance_sent_at')->nullable();
            $table->foreignUuid('recorded_by')->constrained('users');
            $table->timestamps();
            TenantSchema::key($table);
            TenantSchema::foreign($table, 'purchase_order_id', 'purchase_orders');
            TenantSchema::foreign($table, 'supplier_id', 'suppliers');
            $table->index(['organization_id', 'payment_status']);
        });

        // One live invoice per supplier and number; a voided one may be recorded again.
        DB::statement("CREATE UNIQUE INDEX supplier_invoices_live_number ON supplier_invoices (organization_id, supplier_id, number_key) WHERE status = 'recorded'");

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION procurement_audit_append_only() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'the procurement audit trail is append-only';
                END;
                $$ LANGUAGE plpgsql
            SQL);
            DB::statement('CREATE TRIGGER procurement_audit_append_only BEFORE UPDATE OR DELETE ON procurement_audit_events FOR EACH ROW EXECUTE FUNCTION procurement_audit_append_only()');

            foreach ([
                'receipt_lines' => 'accepted_quantity >= 0 AND rejected_quantity >= 0 AND received_quantity = accepted_quantity + rejected_quantity',
                'supplier_invoices' => 'amount_minor > 0',
                'purchase_order_lines' => 'quantity > 0 AND unit_price_minor >= 0',
                'requisition_lines' => 'quantity > 0 AND unit_price_minor >= 0',
                'quotations' => 'total_minor >= 0',
            ] as $table => $check) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_amounts_check CHECK ({$check})");
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS procurement_audit_append_only ON procurement_audit_events');
            DB::statement('DROP FUNCTION IF EXISTS procurement_audit_append_only()');
        }

        foreach ([
            'supplier_invoices', 'receipt_lines', 'receipts', 'purchase_order_revisions', 'purchase_order_lines',
            'purchase_orders', 'awards', 'evaluation_scores', 'quotation_lines', 'quotations', 'evaluation_criteria',
            'sourcing_invitations', 'sourcing_items', 'sourcing_events', 'requisition_lines', 'requisitions',
            'procurement_plan_items', 'procurement_plans', 'approvals', 'approval_rules', 'suppliers',
            'procurement_documents', 'procurement_audit_events', 'document_sequences',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
