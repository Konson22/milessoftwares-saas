<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The industry module whose counter made the sale (App\Support\Industry):
 * each lists, shows and refunds only its own. Every sale so far is the
 * pharmacy's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('application', 32)->default('pharmacy')->after('branch_id');
            $table->index(['organization_id', 'branch_id', 'application', 'sold_at']);
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'branch_id', 'application', 'sold_at']);
            $table->dropColumn('application');
        });
    }
};
