<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Whether manual journals (from the threshold up, or all when it is
        // null) need a second person's approval before they post.
        Schema::table('workspaces', function (Blueprint $table) {
            $table->boolean('journal_approval')->default(false);
            $table->bigInteger('journal_approval_threshold_minor')->nullable();
        });

        // A manual journal waiting for approval. It is not in the ledger, so
        // it moves no balance; approving it posts it.
        Schema::create('journal_drafts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('number', 32);
            $table->date('entry_date');
            $table->string('memo');
            // [{account_id, debit_minor, credit_minor, description, cost_centre_id, project_id}]
            $table->json('lines');
            $table->bigInteger('total_minor');
            $table->string('status', 16);
            $table->foreignUuid('submitted_by')->constrained('users');
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note')->nullable();
            $table->foreignUuid('journal_id')->nullable()->constrained('journals');
            $table->timestamps();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'status']);
        });

        // Who approved a journal that needed it.
        Schema::table('journals', function (Blueprint $table) {
            $table->foreignUuid('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
        });

        // A recurring journal is approved once, after it is added or changed.
        Schema::table('recurring_journals', function (Blueprint $table) {
            $table->foreignUuid('updated_by')->nullable()->constrained('users');
            $table->foreignUuid('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('recurring_journals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('updated_by');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn('approved_at');
        });
        Schema::table('journals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn('approved_at');
        });
        Schema::dropIfExists('journal_drafts');
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn(['journal_approval', 'journal_approval_threshold_minor']);
        });
    }
};
