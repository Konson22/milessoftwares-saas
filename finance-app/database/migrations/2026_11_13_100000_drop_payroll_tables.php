<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll moved out of Finance into the Payroll app (ADR-0010); Finance keeps
 * only the journals Payroll sends it (finance.payroll.posted and .paid). The
 * tables were added on 2026-11-12 and are dropped again here. With any payroll
 * kept in them this refuses to run, so nothing is lost without a decision.
 */
return new class extends Migration
{
    private const TABLES = ['payslips', 'payroll_runs', 'timesheet_entries', 'statutory_rules', 'employee_components', 'pay_components', 'employee_pay', 'employees'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("Finance's {$table} table has rows. Move them to the Payroll app before dropping Finance's payroll tables.");
            }
        }

        $this->previous()->down();
    }

    public function down(): void
    {
        $this->previous()->up();
    }

    private function previous(): Migration
    {
        return require __DIR__.'/2026_11_12_200000_create_payroll_tables.php';
    }
};
