<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Financial years, one after the other without gaps. A closed year's
        // revenue and expenses have been moved into retained earnings by its
        // closing journal.
        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('name', 32);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamp('closed_at')->nullable();
            $table->foreignUuid('closed_by')->nullable()->constrained('users');
            $table->foreignUuid('closing_journal_id')->nullable()->constrained('journals');
            $table->timestamps();

            $table->unique(['organization_id', 'starts_on']);
        });

        // The months of a year. Locked in order, so the books are always
        // locked up to one date: nothing manual posts on or before it.
        Schema::create('fiscal_periods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('fiscal_year_id')->constrained();
            $table->unsignedSmallInteger('number');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamp('locked_at')->nullable();
            $table->foreignUuid('locked_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['fiscal_year_id', 'number']);
            $table->index(['organization_id', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_periods');
        Schema::dropIfExists('fiscal_years');
    }
};
