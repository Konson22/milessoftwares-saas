<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A credit note: what was taken off an invoice raised here, and why.
        Schema::create('customer_credits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('number', 64);
            $table->foreignUuid('invoice_id')->constrained();
            $table->date('credited_on');
            $table->bigInteger('total_minor');
            $table->bigInteger('tax_minor')->default(0);
            $table->string('reason', 255);
            $table->foreignUuid('journal_id')->constrained();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->foreignUuid('void_journal_id')->nullable()->constrained('journals');
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_credits');
    }
};
