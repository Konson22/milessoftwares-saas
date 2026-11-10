<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The database itself refuses a financial record that points at another
 * organization's record. The existing single-column keys stay; these are
 * added beside them. PostgreSQL only: SQLite can't add a foreign key to an
 * existing table, and PostgreSQL is the production engine.
 */
return new class extends Migration
{
    /** Tables that are pointed at, needing a unique (organization_id, id). */
    private const PARENTS = ['journals', 'journal_lines', 'accounts', 'dimension_values', 'tax_codes', 'suppliers', 'bills', 'supplier_payments', 'invoices', 'bank_statements'];

    /** child => column => parent. */
    private const KEYS = [
        'journals' => ['reversal_of_id' => 'journals', 'reversed_by_id' => 'journals'],
        'journal_lines' => ['journal_id' => 'journals', 'account_id' => 'accounts', 'tax_code_id' => 'tax_codes', 'cost_centre_id' => 'dimension_values', 'project_id' => 'dimension_values', 'fund_id' => 'dimension_values'],
        'bills' => ['supplier_id' => 'suppliers', 'journal_id' => 'journals'],
        'bill_payments' => ['bill_id' => 'bills', 'supplier_payment_id' => 'supplier_payments'],
        'supplier_payments' => ['supplier_id' => 'suppliers', 'account_id' => 'accounts', 'journal_id' => 'journals'],
        'supplier_credits' => ['supplier_id' => 'suppliers', 'bill_id' => 'bills', 'journal_id' => 'journals'],
        'invoices' => ['journal_id' => 'journals', 'void_journal_id' => 'journals'],
        'customer_payments' => ['invoice_id' => 'invoices', 'account_id' => 'accounts', 'journal_id' => 'journals'],
        'customer_credits' => ['invoice_id' => 'invoices', 'journal_id' => 'journals', 'void_journal_id' => 'journals'],
        'bank_statements' => ['account_id' => 'accounts'],
        'bank_statement_lines' => ['bank_statement_id' => 'bank_statements', 'account_id' => 'accounts', 'journal_line_id' => 'journal_lines'],
        'expenses' => ['journal_id' => 'journals'],
        'grant_disbursements' => ['journal_id' => 'journals'],
        'tax_returns' => ['settlement_journal_id' => 'journals'],
        'revaluations' => ['journal_id' => 'journals', 'reversal_journal_id' => 'journals'],
        'depreciation_runs' => ['journal_id' => 'journals'],
        'fiscal_years' => ['closing_journal_id' => 'journals'],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::PARENTS as $parent) {
            Schema::table($parent, fn (Blueprint $table) => $table->unique(['organization_id', 'id'], "{$parent}_tenant_key"));
        }

        foreach (self::KEYS as $child => $columns) {
            Schema::table($child, function (Blueprint $table) use ($child, $columns) {
                foreach ($columns as $column => $parent) {
                    $table->foreign(['organization_id', $column], $this->name($child, $column))->references(['organization_id', 'id'])->on($parent);
                }
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::KEYS as $child => $columns) {
            Schema::table($child, function (Blueprint $table) use ($child, $columns) {
                foreach (array_keys($columns) as $column) {
                    $table->dropForeign($this->name($child, $column));
                }
            });
        }

        foreach (self::PARENTS as $parent) {
            Schema::table($parent, fn (Blueprint $table) => $table->dropUnique("{$parent}_tenant_key"));
        }
    }

    private function name(string $child, string $column): string
    {
        return "{$child}_{$column}_tenant_fk";
    }
};
