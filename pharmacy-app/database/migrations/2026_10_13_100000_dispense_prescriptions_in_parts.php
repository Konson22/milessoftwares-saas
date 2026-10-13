<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A prescription can be dispensed over several visits: each item counts
 * what has been given, and each sale names the prescription it filled
 * (replacing the single "dispensed as" sale).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prescription_items', function (Blueprint $table) {
            $table->integer('dispensed_quantity')->default(0);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->foreignUuid('prescription_id')->nullable()->constrained();
            $table->index('prescription_id');
        });

        // Prescriptions dispensed in one go so far: everything was given.
        foreach (DB::table('prescriptions')->whereNotNull('dispensed_sale_id')->get(['id', 'dispensed_sale_id']) as $prescription) {
            DB::table('sales')->where('id', $prescription->dispensed_sale_id)->update(['prescription_id' => $prescription->id]);
            DB::table('prescription_items')->where('prescription_id', $prescription->id)->update(['dispensed_quantity' => DB::raw('quantity')]);
        }

        // prescriptions.dispensed_sale_id is no longer written or read. It
        // stays until the next release (expand, deploy, contract), so the
        // release before this one still runs against this schema.
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('prescription_id');
        });

        Schema::table('prescription_items', function (Blueprint $table) {
            $table->dropColumn('dispensed_quantity');
        });
    }
};
