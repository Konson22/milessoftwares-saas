<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3C (contract §5.3): a request Finance refused may be revised and
 * sent again as a new revision that keeps the original's correlation id,
 * so both sides see one chain of attempts. The correlation id is therefore
 * unique per revision, not on its own. Additive in effect: no row changes.
 *
 * down() puts the old index back; it fails, as it should, once a revision
 * shares a correlation id (that history is not thrown away).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_requests', function (Blueprint $table) {
            $table->dropUnique(['correlation_id']);
            $table->unique(['organization_id', 'correlation_id', 'revision']);
        });
    }

    public function down(): void
    {
        Schema::table('finance_requests', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'correlation_id', 'revision']);
            $table->unique('correlation_id');
        });
    }
};
