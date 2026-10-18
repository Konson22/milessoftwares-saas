<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Manual billing for Miles Global Softwares itself: staff issue invoices,
     * customers report payments made offline (bank transfer, mobile money,
     * cash) and staff confirm them. Customers ask for plan changes; staff
     * decide. Not the customers' own accounting (Finance application).
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('billing_email')->nullable();
            $table->text('billing_address')->nullable();
            $table->string('tax_id', 64)->nullable();
        });

        DB::statement('CREATE SEQUENCE IF NOT EXISTS invoice_number_seq START 1001');

        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('subscription_id')->nullable();
            $table->string('number', 32)->unique();
            $table->string('status', 16)->default('open');
            $table->char('currency', 3);
            $table->unsignedBigInteger('total_minor');
            $table->unsignedBigInteger('amount_paid_minor')->default(0);
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->timestampTz('issued_at');
            $table->date('due_on');
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignUuid('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'status']);
            $table->foreign(['organization_id', 'subscription_id'])->references(['organization_id', 'id'])->on('subscriptions');
        });

        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_status_check CHECK (status IN ('open', 'paid', 'void'))");
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_currency_check CHECK (currency ~ \'^[A-Z]{3}$\')');
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_total_check CHECK (total_minor > 0)');
        // Dropped with the table, so a fresh migration starts numbering again.
        DB::statement('ALTER SEQUENCE invoice_number_seq OWNED BY invoices.number');

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('invoice_id');
            $table->foreignUuid('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedBigInteger('amount_minor');
            $table->timestampsTz();

            $table->foreign(['organization_id', 'invoice_id'])->references(['organization_id', 'id'])->on('invoices')->cascadeOnDelete();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('invoice_id');
            $table->string('status', 16);
            $table->string('method', 24);
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('reference', 120)->nullable();
            $table->date('paid_on');
            $table->text('note')->nullable();
            // A customer report, or staff entering a payment they received.
            $table->foreignUuid('reported_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestampsTz();

            $table->index(['organization_id', 'status']);
            $table->index('status');
            $table->foreign(['organization_id', 'invoice_id'])->references(['organization_id', 'id'])->on('invoices')->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_check CHECK (status IN ('reported', 'confirmed', 'rejected'))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_method_check CHECK (method IN ('bank_transfer', 'mobile_money', 'cash', 'card', 'other'))");
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_check CHECK (amount_minor > 0)');

        Schema::create('plan_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);
            $table->foreignUuid('plan_id')->nullable()->constrained()->restrictOnDelete();
            $table->uuid('subscription_id')->nullable();
            $table->string('status', 16)->default('pending');
            $table->text('note')->nullable();
            $table->foreignUuid('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestampsTz();

            $table->index(['organization_id', 'status']);
            $table->index('status');
            $table->foreign(['organization_id', 'subscription_id'])->references(['organization_id', 'id'])->on('subscriptions')->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE plan_requests ADD CONSTRAINT plan_requests_type_check CHECK (type IN ('subscribe', 'cancel'))");
        DB::statement("ALTER TABLE plan_requests ADD CONSTRAINT plan_requests_status_check CHECK (status IN ('pending', 'approved', 'declined', 'withdrawn'))");
        DB::statement("ALTER TABLE plan_requests ADD CONSTRAINT plan_requests_target_check CHECK ((type = 'subscribe' AND plan_id IS NOT NULL) OR (type = 'cancel' AND subscription_id IS NOT NULL))");
        // One open request per plan or subscription; a double click cannot queue two.
        DB::statement("CREATE UNIQUE INDEX plan_requests_one_pending ON plan_requests (organization_id, type, COALESCE(plan_id, subscription_id)) WHERE status = 'pending'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plan_requests');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
        DB::statement('DROP SEQUENCE IF EXISTS invoice_number_seq');

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['billing_email', 'billing_address', 'tax_id']);
        });
    }
};
