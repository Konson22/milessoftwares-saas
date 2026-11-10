<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How a kind of asset is accounted for and worn down.
        Schema::create('asset_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('name');
            $table->foreignUuid('asset_account_id')->constrained('accounts');
            $table->foreignUuid('accumulated_account_id')->constrained('accounts');
            $table->foreignUuid('expense_account_id')->constrained('accounts');
            // straight_line or declining_balance.
            $table->string('method', 24);
            $table->unsignedSmallInteger('life_months');
            // Declining balance: the yearly rate, in basis points.
            $table->unsignedInteger('rate_bp')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });

        // The register. The cost is already in the asset account (a bill or
        // journal); registering an asset posts nothing.
        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('number', 32);
            $table->string('name');
            $table->foreignUuid('asset_category_id')->constrained('asset_categories');
            $table->date('acquired_on');
            $table->bigInteger('cost_minor');
            $table->bigInteger('residual_minor')->default(0);
            // Depreciation charged before the asset came into Finance.
            $table->bigInteger('opening_depreciation_minor')->default(0);
            $table->string('method', 24);
            $table->unsignedSmallInteger('life_months');
            $table->unsignedInteger('rate_bp')->nullable();
            // The journal that put the cost in the asset account (a bill's or
            // a manual one); null for assets brought in with opening balances.
            $table->foreignUuid('acquisition_journal_id')->nullable()->constrained('journals');
            $table->foreignUuid('bill_id')->nullable()->constrained('bills');
            $table->date('disposed_on')->nullable();
            $table->bigInteger('disposal_proceeds_minor')->nullable();
            $table->foreignUuid('disposal_journal_id')->nullable()->constrained('journals');
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['organization_id', 'number']);
        });

        // One run per month, in order, each posting one journal.
        Schema::create('depreciation_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            // The first day of the month depreciated.
            $table->date('month');
            $table->foreignUuid('journal_id')->nullable()->constrained('journals');
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['organization_id', 'month']);
        });

        Schema::create('depreciation_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('depreciation_run_id')->constrained('depreciation_runs')->cascadeOnDelete();
            $table->foreignUuid('fixed_asset_id')->constrained('fixed_assets');
            $table->bigInteger('amount_minor');

            $table->unique(['depreciation_run_id', 'fixed_asset_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('depreciation_lines');
        Schema::dropIfExists('depreciation_runs');
        Schema::dropIfExists('fixed_assets');
        Schema::dropIfExists('asset_categories');
    }
};
