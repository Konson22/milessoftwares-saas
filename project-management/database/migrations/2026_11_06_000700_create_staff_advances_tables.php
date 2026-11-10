<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Money given to staff ahead of spending it. It stays an asset until receipts
 * are approved, and is never an expense before then. The organization sets
 * the largest advance and how long staff have to account for one.
 */
return new class extends Migration
{
    /** child => column => parent. */
    private const KEYS = [
        'staff_advances' => ['source_account_id' => 'accounts', 'journal_id' => 'journals'],
        'advance_retirements' => ['advance_id' => 'staff_advances'],
        'advance_retirement_lines' => ['retirement_id' => 'advance_retirements', 'account_id' => 'accounts', 'project_id' => 'projects', 'journal_id' => 'journals'],
        'advance_settlements' => ['advance_id' => 'staff_advances', 'account_id' => 'accounts', 'journal_id' => 'journals'],
    ];

    public function up(): void
    {
        Schema::table('accounting_books', function (Blueprint $table) {
            $table->bigInteger('advance_max_minor')->nullable();
            $table->unsignedInteger('advance_retire_days')->nullable();
        });

        Schema::create('staff_advances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->foreignUuid('holder_id')->constrained('users');
            $table->bigInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('purpose');
            $table->string('status', 20)->default('requested');
            $table->foreignUuid('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignUuid('disbursed_by')->nullable()->constrained('users');
            $table->date('disbursed_on')->nullable();
            $table->string('reference')->nullable();
            $table->foreignUuid('source_account_id')->nullable()->constrained('accounts');
            $table->foreignUuid('journal_id')->nullable()->constrained();
            // Set when paid out, from the policy of that day.
            $table->date('due_on')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'number']);
            $table->unique(['organization_id', 'id'], 'staff_advances_tenant_key');
            $table->index(['organization_id', 'holder_id', 'status']);
            $table->index(['organization_id', 'status', 'due_on']);
        });

        Schema::create('advance_retirements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('advance_id')->constrained('staff_advances');
            $table->string('status', 20)->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignUuid('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->text('returned_reason')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'id'], 'advance_retirements_tenant_key');
            $table->index(['advance_id', 'status']);
        });

        Schema::create('advance_retirement_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('retirement_id')->constrained('advance_retirements');
            $table->date('spent_on');
            $table->bigInteger('amount_minor');
            $table->string('description');
            $table->string('payee')->nullable();
            // What it was spent on: an expense account, or a project's category.
            $table->foreignUuid('account_id')->nullable()->constrained();
            $table->foreignUuid('project_id')->nullable()->constrained();
            $table->string('category', 30)->nullable();
            $table->foreignUuid('expense_id')->nullable()->constrained();
            $table->string('receipt_name');
            $table->string('receipt_path');
            $table->string('receipt_mime');
            $table->unsignedBigInteger('receipt_size');
            $table->foreignUuid('journal_id')->nullable()->constrained();
            $table->timestamps();

            $table->index('retirement_id');
        });

        // Cash handed back, or written off with a second person's approval.
        Schema::create('advance_settlements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('advance_id')->constrained('staff_advances');
            $table->string('type', 20);
            $table->string('status', 20);
            $table->bigInteger('amount_minor');
            $table->date('on');
            $table->text('reason')->nullable();
            $table->foreignUuid('account_id')->nullable()->constrained();
            $table->foreignUuid('journal_id')->nullable()->constrained();
            $table->foreignUuid('recorded_by')->constrained('users');
            $table->foreignUuid('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->index(['advance_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            foreach (self::KEYS as $child => $columns) {
                Schema::table($child, function (Blueprint $table) use ($child, $columns) {
                    foreach ($columns as $column => $parent) {
                        $table->foreign(['organization_id', $column], "{$child}_{$column}_tenant_fk")->references(['organization_id', 'id'])->on($parent);
                    }
                });
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('advance_settlements');
        Schema::dropIfExists('advance_retirement_lines');
        Schema::dropIfExists('advance_retirements');
        Schema::dropIfExists('staff_advances');

        Schema::table('accounting_books', function (Blueprint $table) {
            $table->dropColumn(['advance_max_minor', 'advance_retire_days']);
        });
    }
};
