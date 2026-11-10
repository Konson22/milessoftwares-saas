<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| A box moved into a Finance petty cash fund (status 'moved'): kept here for
| its history and receipts, read only.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('petty_cash_funds', function (Blueprint $table) {
            $table->timestamp('moved_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('petty_cash_funds', function (Blueprint $table) {
            $table->dropColumn('moved_at');
        });
    }
};
