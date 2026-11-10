<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rates the organization charges on sales and pays on purchases.
        Schema::create('tax_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('code', 16);
            $table->string('name');
            // Basis points: 1800 is 18%.
            $table->unsignedInteger('rate_bp');
            $table->boolean('sales')->default(true);
            $table->boolean('purchases')->default(true);
            // Purchase tax claimed back from the tax authority; otherwise it
            // is part of the cost.
            $table->boolean('recoverable')->default(true);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
        });

        // A line on the tax account says which tax it is: output (on sales)
        // or input (on purchases), its code when it has one, and the net
        // amount it was charged on. Set when the line is posted, like the
        // rest of the line.
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->string('tax_kind', 8)->nullable();
            $table->foreignUuid('tax_code_id')->nullable()->constrained();
            $table->bigInteger('tax_base_minor')->nullable();
        });

        // Tax returns, one after the other. Filing one locks its period for
        // anything that touches the tax account.
        Schema::create('tax_returns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('reference', 64)->nullable();
            $table->bigInteger('output_minor');
            $table->bigInteger('input_minor');
            $table->bigInteger('adjustments_minor');
            // Owed to the tax authority (negative: owed back).
            $table->bigInteger('net_minor');
            $table->timestamp('filed_at');
            $table->foreignUuid('filed_by')->constrained('users');
            $table->foreignUuid('settlement_journal_id')->nullable()->constrained('journals');
            $table->timestamps();

            $table->unique(['organization_id', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_returns');
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tax_code_id');
            $table->dropColumn(['tax_kind', 'tax_base_minor']);
        });
        Schema::dropIfExists('tax_codes');
    }
};
