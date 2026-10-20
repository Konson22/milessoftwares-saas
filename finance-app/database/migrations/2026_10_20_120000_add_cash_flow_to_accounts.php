<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // operating, investing or financing; null follows the rule in
        // App\Models\Account::cashFlowActivity().
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('cash_flow', 16)->nullable()->after('system_key');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('cash_flow');
        });
    }
};
