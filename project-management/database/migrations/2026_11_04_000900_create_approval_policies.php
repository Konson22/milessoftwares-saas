<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many people approve spending, by amount: each band reads "from this
 * amount, in this currency, this many approvers". Without a band, one.
 * The number is fixed on the request when it is made, and each approval
 * is its own row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            // What it governs; spending requests for now.
            $table->string('subject', 32)->default('expense');
            $table->char('currency', 3);
            // From this amount up (inclusive), in minor units.
            $table->unsignedBigInteger('min_amount_minor');
            $table->unsignedTinyInteger('approvals_required');
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();
            $table->unique(['organization_id', 'subject', 'currency', 'min_amount_minor'], 'approval_policies_band_unique');
        });

        Schema::table('expenses', function (Blueprint $table) {
            // Set when requested, from the policy then in force.
            $table->unsignedTinyInteger('approvals_required')->default(1)->after('status');
        });

        Schema::create('expense_approvals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('expense_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('note', 2000)->nullable();
            $table->timestamp('created_at');
            // One approval per person.
            $table->unique(['expense_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_approvals');
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn('approvals_required');
        });
        Schema::dropIfExists('approval_policies');
    }
};
