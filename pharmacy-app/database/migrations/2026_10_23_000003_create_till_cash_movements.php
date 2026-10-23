<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cash put into or taken out of an open till other than by selling or
 * giving back. To or from the safe it stays within the business's cash, so
 * the till's expected cash changes and the books do not; banked, taken
 * from the bank, or paid out for an expense, Finance hears of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('till_cash_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->foreignUuid('till_session_id')->constrained();
            // in | out
            $table->string('direction', 8);
            // safe | bank | expense (out only)
            $table->string('counterpart', 16)->default('safe');
            $table->bigInteger('amount_minor');
            $table->string('reason', 255);
            $table->foreignUuid('recorded_by')->constrained('users');
            // The app's id for the request, so a retry records it once.
            $table->uuid('submission_id')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'till_session_id']);
            $table->unique(['organization_id', 'submission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('till_cash_movements');
    }
};
