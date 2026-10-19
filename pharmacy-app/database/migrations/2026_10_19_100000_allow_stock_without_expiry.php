<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Goods that never expire (most of a shop's shelves): an item says whether
 * its stock carries an expiry date, and batches of items that do not have
 * none. Every item so far is a medicine, which always does.
 */
return new class extends Migration
{
    private const DATED = ['batches', 'goods_receipt_lines', 'stock_transfer_lines'];

    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->boolean('tracks_expiry')->default(true)->after('controlled');
        });

        foreach (self::DATED as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->date('expires_on')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Stock without a date cannot be given one: refuse instead of inventing it.
        foreach (self::DATED as $name) {
            if (DB::table($name)->whereNull('expires_on')->exists()) {
                throw new RuntimeException("{$name} has rows without an expiry date; remove them before rolling back.");
            }
        }

        foreach (self::DATED as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->date('expires_on')->nullable(false)->change();
            });
        }

        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('tracks_expiry');
        });
    }
};
