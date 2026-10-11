<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per batch of audit records shipped off-site, so each export
     * continues where the last one ended.
     */
    public function up(): void
    {
        Schema::create('audit_exports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('first_sequence');
            $table->unsignedBigInteger('last_sequence')->unique();
            $table->char('last_hash', 64);
            $table->unsignedInteger('row_count');
            $table->string('path');
            $table->timestampTz('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_exports');
    }
};
