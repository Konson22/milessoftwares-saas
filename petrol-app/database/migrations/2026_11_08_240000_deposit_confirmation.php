<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_deposits', function (Blueprint $table) {
            $table->string('bank_name')->nullable()->after('bank_reference');
            $table->bigInteger('confirmed_amount_minor')->nullable()->after('amount_minor');
        });
    }

    public function down(): void
    {
        Schema::table('bank_deposits', function (Blueprint $table) {
            $table->dropColumn(['bank_name', 'confirmed_amount_minor']);
        });
    }
};
