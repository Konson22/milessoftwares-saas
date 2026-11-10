<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What an organization has switched off (everything is on until then), and
     * who in it must use two-factor sign-in.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->json('disabled_modules')->nullable()->after('capabilities');
            $table->string('two_factor_policy', 16)->default('off')->after('disabled_modules');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['disabled_modules', 'two_factor_policy']);
        });
    }
};
