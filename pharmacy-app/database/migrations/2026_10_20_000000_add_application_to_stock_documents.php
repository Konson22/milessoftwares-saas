<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The industry module whose screens made each purchasing and stock
 * document (App\Support\Industry), like sales.application: each lists and
 * works on only its own. Everything so far is the pharmacy's.
 */
return new class extends Migration
{
    /** Table => the branch column its lists filter on. */
    private const TABLES = [
        'purchase_orders' => 'branch_id',
        'goods_receipts' => 'branch_id',
        'supplier_returns' => 'branch_id',
        'stock_disposals' => 'branch_id',
        'stock_transfers' => 'from_branch_id',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $name => $branch) {
            Schema::table($name, function (Blueprint $table) use ($branch) {
                $table->string('application', 32)->default('pharmacy')->after($branch);
                $table->index(['organization_id', 'application']);
            });
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TABLES) as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropIndex(['organization_id', 'application']);
                $table->dropColumn('application');
            });
        }
    }
};
