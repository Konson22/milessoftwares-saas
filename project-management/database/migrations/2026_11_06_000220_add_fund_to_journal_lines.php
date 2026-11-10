<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which fund a line belongs to, as a label alongside cost centre and project.
 * It records and reports; it does not decide how grant income is recognized.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->foreignUuid('fund_id')->nullable()->constrained('dimension_values');
        });
    }

    public function down(): void
    {
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fund_id');
        });
    }
};
