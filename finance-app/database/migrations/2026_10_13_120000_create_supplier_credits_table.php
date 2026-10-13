<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What a supplier owes back (goods returned): taken off their bill.
        Schema::create('supplier_credits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('supplier_id')->constrained();
            $table->string('number', 64);
            $table->string('source_app', 32);
            $table->foreignUuid('bill_id')->nullable()->constrained();
            $table->uuid('branch_id')->nullable();
            $table->date('credited_on');
            $table->bigInteger('total_minor');
            $table->bigInteger('tax_minor')->default(0);
            // What was set against the bill; the rest stays on account.
            $table->bigInteger('applied_minor')->default(0);
            $table->string('reason', 255);
            $table->json('lines');
            $table->foreignUuid('journal_id')->nullable()->constrained();
            $table->timestamps();

            $table->unique(['organization_id', 'source_app', 'number']);
            $table->index(['organization_id', 'supplier_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_credits');
    }
};
