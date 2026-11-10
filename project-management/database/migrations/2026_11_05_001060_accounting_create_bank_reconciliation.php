<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How the bank's CSV export is laid out, remembered for next time.
        Schema::table('accounts', function (Blueprint $table) {
            $table->json('bank_import')->nullable()->after('bank_account_number');
        });

        // A bank statement, imported for one bank or cash account. Each one
        // opens with the previous one's closing balance.
        Schema::create('bank_statements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('account_id')->constrained();
            $table->string('reference', 64)->nullable();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->bigInteger('opening_balance_minor');
            $table->bigInteger('closing_balance_minor');
            $table->timestamp('reconciled_at')->nullable();
            $table->foreignUuid('reconciled_by')->nullable()->constrained('users');
            $table->foreignUuid('imported_by')->constrained('users');
            $table->timestamps();

            $table->index(['organization_id', 'account_id', 'ends_on']);
        });

        // Its lines: money in positive, money out negative. A line is matched
        // to the one ledger line it clears, and a ledger line clears once.
        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('bank_statement_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('account_id')->constrained();
            $table->unsignedInteger('position');
            $table->date('line_date');
            $table->string('description', 255);
            $table->string('reference', 64)->nullable();
            $table->bigInteger('amount_minor');
            $table->foreignUuid('journal_line_id')->nullable()->unique()->constrained();
            $table->timestamp('matched_at')->nullable();
            $table->foreignUuid('matched_by')->nullable()->constrained('users');

            $table->index(['organization_id', 'account_id', 'line_date']);
        });

        // "Description contains … → post to …" for lines nothing in the
        // ledger matches.
        Schema::create('bank_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            // Null: every bank account.
            $table->foreignUuid('bank_account_id')->nullable()->constrained('accounts');
            $table->string('contains', 100);
            // in, out or any.
            $table->string('direction', 8);
            $table->foreignUuid('account_id')->constrained();
            $table->string('memo', 255)->nullable();
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->index(['organization_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_rules');
        Schema::dropIfExists('bank_statement_lines');
        Schema::dropIfExists('bank_statements');
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('bank_import');
        });
    }
};
