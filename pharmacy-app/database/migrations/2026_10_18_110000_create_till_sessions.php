<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A cashier's till from opening (with a float) to the count at closing.
 * Every counter sale is taken into the seller's open till; a refund comes
 * out of the till of whoever gives it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('till_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->uuid('branch_id');
            $table->string('number', 32);
            // open → closed
            $table->string('status', 16);
            $table->foreignUuid('opened_by')->constrained('users');
            $table->timestamp('opened_at');
            $table->bigInteger('opening_float_minor');
            $table->foreignUuid('closed_by')->nullable()->constrained('users');
            $table->timestamp('closed_at')->nullable();
            // Fixed at closing, so later changes never rewrite a count.
            $table->bigInteger('expected_cash_minor')->nullable();
            $table->bigInteger('counted_cash_minor')->nullable();
            $table->string('closing_note', 255)->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'branch_id', 'opened_at']);
        });

        // One open till per person per branch.
        DB::statement("CREATE UNIQUE INDEX till_sessions_one_open ON till_sessions (organization_id, branch_id, opened_by) WHERE status = 'open'");

        Schema::table('sales', function (Blueprint $table) {
            $table->foreignUuid('till_session_id')->nullable()->constrained();
            $table->foreignUuid('void_till_session_id')->nullable()->constrained('till_sessions');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('void_till_session_id');
            $table->dropConstrainedForeignId('till_session_id');
        });

        Schema::dropIfExists('till_sessions');
    }
};
