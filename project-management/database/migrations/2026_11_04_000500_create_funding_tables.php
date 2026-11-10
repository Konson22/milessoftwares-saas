<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Funding: the organization's funders and the grants it pursues and
     * receives from them. A grant can fund several projects and a project
     * can draw on several grants (grant_allocations), so projects are never
     * duplicated per donor. Amounts are integers in the minor unit of the
     * currency named next to them.
     */
    public function up(): void
    {
        // Who funds the organization: institutional donors, foundations,
        // governments... (its own directory, not a global type).
        Schema::create('funders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('kind', 24);
            $table->char('country_code', 2)->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('website')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'name']);
        });

        // One record from opportunity to closeout: opportunity → applied →
        // active (awarded: the agreement) → closed; or declined.
        Schema::create('grants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('funder_id')->constrained()->restrictOnDelete();
            $table->string('reference', 40);
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 16);
            $table->char('currency', 3);
            // Requested until awarded; then the awarded amount, changed only
            // by an amendment (grant_amendments).
            $table->unsignedBigInteger('amount_minor');
            // Restricted: only for the purposes and projects agreed.
            $table->boolean('restricted')->default(true);
            $table->date('start_on')->nullable();
            $table->date('end_on')->nullable();
            $table->date('applied_on')->nullable();
            $table->date('awarded_on')->nullable();
            $table->date('closed_on')->nullable();
            $table->date('declined_on')->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();
            $table->unique(['organization_id', 'reference']);
            $table->index(['organization_id', 'status']);
        });

        // Part of a grant given to a project, in the grant's currency, and
        // what it is worth in the project's currency at the rate recorded.
        Schema::create('grant_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('grant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('project_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            // Project currency per one unit of grant currency; 1 when the same.
            $table->decimal('exchange_rate', 20, 8)->default(1);
            $table->unsignedBigInteger('project_amount_minor');
            $table->string('note')->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();
            $table->unique(['grant_id', 'project_id']);
        });

        // The payment schedule, and what has arrived.
        Schema::create('grant_disbursements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('grant_id')->constrained()->restrictOnDelete();
            $table->date('due_on');
            $table->unsignedBigInteger('amount_minor');
            $table->date('received_on')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();
            $table->index(['grant_id', 'due_on']);
        });

        // What the funder expects to receive, and when.
        Schema::create('grant_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('grant_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->string('kind', 16);
            $table->date('due_on');
            $table->string('status', 24);
            $table->date('submitted_on')->nullable();
            $table->date('accepted_on')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status', 'due_on']);
        });

        // Changes to an awarded grant's amount or end date, numbered; never
        // edited or deleted.
        Schema::create('grant_amendments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('grant_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->date('effective_on');
            $table->text('reason');
            $table->unsignedBigInteger('amount_before_minor');
            $table->unsignedBigInteger('amount_after_minor');
            $table->date('end_before')->nullable();
            $table->date('end_after')->nullable();
            $table->foreignUuid('recorded_by')->constrained('users');
            $table->timestamp('created_at');
            $table->unique(['grant_id', 'number']);
        });
    }

    public function down(): void
    {
        foreach (['grant_amendments', 'grant_reports', 'grant_disbursements', 'grant_allocations', 'grants', 'funders'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
