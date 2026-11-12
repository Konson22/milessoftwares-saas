<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Payroll is part of Finance & Accounting now (ADR-0009): the standalone
 * Payroll product is retired (not deleted: plans may point at it) and no ERP
 * bundle offers it as an optional application any more. The payroll
 * application was never registered, so no organization holds it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('erp_products')
            ->where('code', 'payroll')
            ->update(['status' => 'retired', 'updated_at' => now()]);

        DB::table('erp_product_applications')
            ->whereIn('application_id', DB::table('applications')->where('code', 'payroll')->select('id'))
            ->delete();
    }

    public function down(): void
    {
        DB::table('erp_products')
            ->where('code', 'payroll')
            ->update(['status' => 'planned', 'updated_at' => now()]);
    }
};
