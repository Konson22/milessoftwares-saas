<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Procurement's settings for each organization, created when the
        // platform provisions the organization (App\Platform\SetUpWorkspace)
        // or on first use. The currency is the workspace currency every
        // amount is compared and approved in (platform-client EntryCurrencies).
        Schema::create('workspaces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->unique();
            $table->string('display_name');
            $table->char('currency', 3);
            $table->string('timezone', 64);
            // Separation of duties: whoever submitted a document may not decide it.
            $table->boolean('allow_self_approval')->default(false);
            // Requisitions must name an approved procurement plan item.
            $table->boolean('require_plan')->default(false);
            // Quotations expected before an award; fewer needs a written reason.
            $table->unsignedSmallInteger('min_quotations')->default(3);
            // Upper limit per method, in the workspace currency's minor units:
            // {"direct": 50000, "rfq": 1000000}. A method without one has no limit.
            $table->json('method_limits')->nullable();
            // Invoices may exceed the accepted value by this share (0 = exact).
            $table->unsignedSmallInteger('invoice_tolerance_percent')->default(0);
            $table->text('delivery_address')->nullable();
            $table->text('order_terms')->nullable();
            // Finance connection (ADR-0023 §2.7): off until switched on.
            $table->boolean('finance_bills_enabled')->default(false);
            $table->string('finance_expense_account', 32)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspaces');
    }
};
