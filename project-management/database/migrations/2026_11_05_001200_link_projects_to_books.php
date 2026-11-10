<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Connects project money to the books: which journal a paid expense or a
 * received grant payment posted, which accounting project a project posts
 * to, and which accounts the organization posts them on. Additive: rows
 * paid before this stay unposted until "Post earlier records" is run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignUuid('journal_id')->nullable()->constrained('journals')->restrictOnDelete();
        });
        Schema::table('grant_disbursements', function (Blueprint $table) {
            $table->foreignUuid('journal_id')->nullable()->constrained('journals')->restrictOnDelete();
        });
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignUuid('dimension_value_id')->nullable()->constrained('dimension_values')->restrictOnDelete();
        });

        Schema::create('project_posting_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            // payment | grant_income | a cost category
            $table->string('key', 32);
            $table->foreignUuid('account_id')->constrained('accounts')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_posting_accounts');
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dimension_value_id');
        });
        Schema::table('grant_disbursements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('journal_id');
        });
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('journal_id');
        });
    }
};
