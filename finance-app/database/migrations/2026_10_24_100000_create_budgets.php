<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A plan of income and spending for a financial year, for the whole
        // organization or one cost centre.
        Schema::create('budgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('fiscal_year_id')->constrained('fiscal_years');
            $table->string('name');
            $table->foreignUuid('cost_centre_id')->nullable()->constrained('dimension_values');
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['organization_id', 'fiscal_year_id', 'name']);
        });

        // One amount per account and month of the year (period number), in
        // the account's normal direction: income for revenue, spending for
        // expenses.
        Schema::create('budget_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('budget_id')->constrained('budgets')->cascadeOnDelete();
            $table->foreignUuid('account_id')->constrained('accounts');
            $table->unsignedSmallInteger('period');
            $table->bigInteger('amount_minor');

            $table->unique(['budget_id', 'account_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_lines');
        Schema::dropIfExists('budgets');
    }
};
