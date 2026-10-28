<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A journal posted again and again (rent, accruals, prepaid
        // amortisation, standard reclasses). Occurrence n is dated starts_on
        // plus n intervals, so the 31st stays the month end. A reversing
        // template's journals are reversed the day after they are dated.
        Schema::create('recurring_journals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('name');
            $table->string('memo');
            $table->string('frequency', 16);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->date('next_on')->nullable();
            $table->unsignedInteger('posted_count')->default(0);
            $table->boolean('reverses')->default(false);
            $table->boolean('active')->default(true);
            // [{account_id, debit_minor, credit_minor, description, cost_centre_id, project_id}]
            $table->json('lines');
            $table->foreignUuid('last_journal_id')->nullable()->constrained('journals');
            $table->string('last_error')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
            $table->index(['organization_id', 'active', 'next_on']);
        });

        // Which journals a template posted, and which occurrence each was.
        Schema::table('journals', function (Blueprint $table) {
            $table->foreignUuid('recurring_journal_id')->nullable()->constrained('recurring_journals')->nullOnDelete();
            $table->unsignedInteger('recurring_occurrence')->nullable();
            $table->unique(['recurring_journal_id', 'recurring_occurrence']);
        });
    }

    public function down(): void
    {
        Schema::table('journals', function (Blueprint $table) {
            $table->dropUnique(['recurring_journal_id', 'recurring_occurrence']);
            $table->dropConstrainedForeignId('recurring_journal_id');
            $table->dropColumn('recurring_occurrence');
        });
        Schema::dropIfExists('recurring_journals');
    }
};
