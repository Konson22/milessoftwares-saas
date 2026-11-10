<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * bill_payments was the one financial table with no owner of its own, so it
 * had no tenant scope. Existing rows take the organization of their bill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bill_payments', function (Blueprint $table) {
            $table->uuid('organization_id')->nullable();
        });

        DB::table('bill_payments')->update([
            'organization_id' => DB::raw('(SELECT bills.organization_id FROM bills WHERE bills.id = bill_payments.bill_id)'),
        ]);

        Schema::table('bill_payments', function (Blueprint $table) {
            $table->uuid('organization_id')->nullable(false)->change();
            $table->index(['organization_id', 'bill_id']);
        });
    }

    public function down(): void
    {
        Schema::table('bill_payments', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'bill_id']);
            $table->dropColumn('organization_id');
        });
    }
};
