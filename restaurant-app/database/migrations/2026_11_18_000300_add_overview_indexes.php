<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The all-branches overview reads refunds by the day they were voided and
 * the kitchens' oldest waiting tickets; sales by day (branch_id, paid_at)
 * and orders by day (branch_id, opened_at) are indexed already.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->index(['branch_id', 'voided_at']);
        });
        Schema::table('kitchen_tickets', function (Blueprint $table) {
            $table->index(['branch_id', 'status', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->dropIndex(['branch_id', 'voided_at']);
        });
        Schema::table('kitchen_tickets', function (Blueprint $table) {
            $table->dropIndex(['branch_id', 'status', 'sent_at']);
        });
    }
};
