<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Other currencies people may type amounts in, with today's rate:
        // workspace-currency minor units for one major unit (SSP 7,800.00
        // per USD is 780000). The workspace currency itself has no row.
        Schema::create('entry_currencies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->char('code', 3);
            $table->unsignedTinyInteger('decimals')->default(2);
            $table->unsignedBigInteger('rate_minor');
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
        });

        // What was typed when it was not the workspace currency: the record
        // keeps the converted amount in its own column, this keeps the rest.
        Schema::create('entered_amounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('subject_type');
            $table->string('subject_id', 64);
            $table->string('field', 64);
            $table->char('currency', 3);
            $table->bigInteger('amount_minor');
            $table->unsignedBigInteger('rate_minor');
            $table->char('base_currency', 3);
            $table->bigInteger('base_minor');
            $table->uuid('created_by')->nullable();
            $table->timestamp('created_at');
            $table->index(['organization_id', 'subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entered_amounts');
        Schema::dropIfExists('entry_currencies');
    }
};
