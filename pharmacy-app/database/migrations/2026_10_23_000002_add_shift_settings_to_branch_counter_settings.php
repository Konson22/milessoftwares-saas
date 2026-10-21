<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How a branch runs cash shifts (till sessions): whether money is taken
     * only in an open shift, the usual starting cash, a blind count at
     * closing, and whether only managers close shifts.
     */
    public function up(): void
    {
        Schema::table('branch_counter_settings', function (Blueprint $table) {
            $table->boolean('shifts_required')->default(true)->after('direct_payment');
            $table->bigInteger('default_float_minor')->nullable()->after('shifts_required');
            $table->boolean('blind_close')->default(false)->after('default_float_minor');
            $table->boolean('manager_closes_shifts')->default(false)->after('blind_close');
        });
    }

    public function down(): void
    {
        Schema::table('branch_counter_settings', function (Blueprint $table) {
            $table->dropColumn(['shifts_required', 'default_float_minor', 'blind_close', 'manager_closes_shifts']);
        });
    }
};
