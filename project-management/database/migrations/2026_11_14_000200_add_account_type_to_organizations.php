<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the organization uses Projects as: a funder (donor) or an implementer.
 * Chosen once by someone who manages it, before anything else (null until
 * then); it decides which sections its menu shows. Additive: existing
 * organizations choose on their next visit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('account_type', 16)->nullable()->after('capabilities');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('account_type');
        });
    }
};
