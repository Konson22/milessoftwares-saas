<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * An order paid in parts (split bills) and bills with a discount.
 *
 * An order may have several bills: some of its lines each (split by item),
 * or equal shares of what is left (part k of parts N; such a bill has one
 * "Share k of N" line with no menu item). order_lines.bill_id is the bill
 * that paid the line. A bill's subtotal is what it charged before its
 * discount; total_minor stays what was taken.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->dropUnique(['order_id']);
            $table->index('order_id');
            $table->unsignedBigInteger('subtotal_minor')->default(0)->after('currency');
            $table->unsignedBigInteger('discount_minor')->default(0)->after('subtotal_minor');
            $table->string('discount_reason')->nullable()->after('discount_minor');
            $table->foreignUuid('discounted_by')->nullable()->after('discount_reason')->constrained('users');
            $table->unsignedSmallInteger('part')->nullable()->after('discounted_by');
            $table->unsignedSmallInteger('parts')->nullable()->after('part');
        });
        DB::table('bills')->update(['subtotal_minor' => DB::raw('total_minor')]);

        Schema::table('bill_lines', function (Blueprint $table) {
            $table->uuid('menu_item_id')->nullable()->change();
        });

        Schema::table('order_lines', function (Blueprint $table) {
            $table->foreignUuid('bill_id')->nullable()->constrained('bills');
        });
        // Every line billed so far was billed whole, by its order's one bill.
        foreach (DB::table('bills')->get(['id', 'order_id']) as $bill) {
            DB::table('order_lines')->where('order_id', $bill->order_id)->whereNull('voided_at')->update(['bill_id' => $bill->id]);
        }
    }

    public function down(): void
    {
        Schema::table('order_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bill_id');
        });
        Schema::table('bill_lines', function (Blueprint $table) {
            $table->uuid('menu_item_id')->nullable(false)->change();
        });
        Schema::table('bills', function (Blueprint $table) {
            $table->dropConstrainedForeignId('discounted_by');
            $table->dropColumn(['subtotal_minor', 'discount_minor', 'discount_reason', 'part', 'parts']);
            $table->dropIndex(['order_id']);
            $table->unique('order_id');
        });
    }
};
