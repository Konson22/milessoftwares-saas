<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The books are Finance's (the shared module): Projects sends it what was
 * paid, received and advanced (finance.* events) and keeps no ledger of its
 * own. Petty cash, staff advances, expenses and suppliers stay as records;
 * their links to accounts and journals go, and the organization keeps the
 * finance limits that were on the books.
 */
return new class extends Migration
{
    /** Columns that pointed into the books. */
    private const LINKS = [
        'expenses' => ['journal_id'],
        'grant_disbursements' => ['journal_id'],
        'projects' => ['dimension_value_id'],
        'petty_cash_funds' => ['account_id', 'journal_id', 'closing_journal_id'],
        'petty_cash_top_ups' => ['source_account_id', 'journal_id'],
        'petty_cash_payments' => ['account_id', 'journal_id', 'void_journal_id'],
        'petty_cash_counts' => ['journal_id'],
        'staff_advances' => ['source_account_id', 'journal_id'],
        'advance_retirement_lines' => ['account_id', 'journal_id'],
        'advance_settlements' => ['account_id', 'journal_id'],
    ];

    /** The ledger's own tables; suppliers and document numbers stay. */
    private const TABLES = [
        'project_posting_accounts', 'journal_drafts', 'recurring_journals', 'depreciation_lines', 'depreciation_runs',
        'fixed_assets', 'asset_categories', 'accounting_budget_lines', 'accounting_budgets', 'revaluations', 'exchange_rates',
        'tax_returns', 'bank_rules', 'bank_statement_lines', 'bank_statements', 'customer_credits', 'customer_payments',
        'supplier_credits', 'bill_payments', 'supplier_payments', 'bills', 'invoices', 'fiscal_periods', 'fiscal_years',
        'journal_lines', 'journals', 'tax_codes', 'dimension_values', 'accounts', 'accounting_books',
    ];

    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->bigInteger('petty_cash_max_payment_minor')->nullable();
            $table->bigInteger('advance_max_minor')->nullable();
            $table->unsignedInteger('advance_retire_days')->nullable();
        });
        if (Schema::hasTable('accounting_books')) {
            foreach (DB::table('accounting_books')->get(['organization_id', 'petty_cash_max_payment_minor', 'advance_max_minor', 'advance_retire_days']) as $books) {
                DB::table('organizations')->where('id', $books->organization_id)->update([
                    'petty_cash_max_payment_minor' => $books->petty_cash_max_payment_minor,
                    'advance_max_minor' => $books->advance_max_minor,
                    'advance_retire_days' => $books->advance_retire_days,
                ]);
            }
        }

        $pgsql = DB::getDriverName() === 'pgsql';
        if (! $pgsql) {
            Schema::disableForeignKeyConstraints();
        }

        foreach (self::LINKS as $table => $columns) {
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }
                if ($pgsql) {
                    // With the foreign keys (the tenant ones too) and indexes on it.
                    DB::statement("ALTER TABLE {$table} DROP COLUMN {$column} CASCADE");

                    continue;
                }
                Schema::table($table, function (Blueprint $blueprint) use ($table, $column) {
                    if ($table === 'petty_cash_funds' && $column === 'account_id') {
                        $blueprint->dropUnique(['account_id']);
                    }
                    $blueprint->dropForeign([$column]);
                });
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn($column));
            }
        }

        // What Finance is told instead of an account.
        Schema::table('petty_cash_payments', fn (Blueprint $table) => $table->string('category', 30)->nullable());
        Schema::table('staff_advances', fn (Blueprint $table) => $table->string('payment_method', 20)->nullable());
        Schema::table('advance_settlements', fn (Blueprint $table) => $table->string('payment_method', 20)->nullable());

        if ($pgsql) {
            DB::statement('DROP TABLE IF EXISTS '.implode(', ', self::TABLES).' CASCADE');
            DB::statement('DROP FUNCTION IF EXISTS journals_protected()');
        } else {
            foreach (self::TABLES as $table) {
                Schema::dropIfExists($table);
            }
            Schema::enableForeignKeyConstraints();
        }
    }

    public function down(): void
    {
        throw new RuntimeException('The books moved to Finance; restore a backup to go back.');
    }
};
