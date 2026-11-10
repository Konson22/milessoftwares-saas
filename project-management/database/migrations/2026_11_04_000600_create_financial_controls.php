<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Financial controls on project money:
     * - budgets are versioned: lines belong to a version that is drafted,
     *   submitted and approved; an approved version is never edited, a
     *   revision is a new version;
     * - spending goes requested → approved (a commitment) → paid, in the
     *   one expenses table, so committed and spent are never both counted;
     * - expenses carry their funding source (a grant) and supporting
     *   documents.
     */
    public function up(): void
    {
        Schema::create('budget_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('project_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('number');
            // draft → submitted → approved → superseded (by the next approved);
            // a submitted version can be returned to draft.
            $table->string('status', 16);
            $table->unsignedBigInteger('total_minor')->default(0);
            $table->string('note')->nullable();
            $table->foreignUuid('submitted_by')->nullable()->constrained('users');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignUuid('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->string('returned_reason')->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();
            $table->unique(['project_id', 'number']);
        });

        Schema::table('budget_lines', function (Blueprint $table) {
            $table->foreignUuid('budget_version_id')->nullable()->after('project_id')->constrained()->restrictOnDelete();
        });

        // Lines from before versioning become each project's first draft.
        foreach (DB::table('budget_lines')->distinct()->pluck('project_id') as $projectId) {
            $project = DB::table('projects')->where('id', $projectId)->first(['organization_id', 'created_by']);
            $versionId = (string) Str::uuid7();
            DB::table('budget_versions')->insert([
                'id' => $versionId,
                'organization_id' => $project->organization_id,
                'project_id' => $projectId,
                'number' => 1,
                'status' => 'draft',
                'total_minor' => (int) DB::table('budget_lines')->where('project_id', $projectId)->sum('amount_minor'),
                'created_by' => $project->created_by,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('budget_lines')->where('project_id', $projectId)->update(['budget_version_id' => $versionId]);
        }

        Schema::table('budget_lines', function (Blueprint $table) {
            $table->uuid('budget_version_id')->nullable(false)->change();
        });

        Schema::table('expenses', function (Blueprint $table) {
            // requested | approved | paid | rejected | cancelled. Recorded
            // before this: paid.
            $table->string('status', 16)->default('paid')->after('amount_minor');
            // Paid on (set when paid; null while requested or approved).
            $table->date('spent_on')->nullable()->change();
            $table->foreignUuid('grant_id')->nullable()->after('project_id')->constrained()->restrictOnDelete();
            // Recorded straight as paid (no request and approval).
            $table->boolean('direct')->default(false);
            $table->foreignUuid('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note')->nullable();
            $table->foreignUuid('paid_by')->nullable()->constrained('users');
            $table->string('payment_reference', 100)->nullable();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users');
            $table->timestamp('cancelled_at')->nullable();
            $table->index(['project_id', 'status', 'category']);
            $table->index(['organization_id', 'status']);
        });

        DB::table('expenses')->update(['direct' => true]);

        // Receipts, invoices, quotes: files on the private disk, served only
        // through an authorized route.
        Schema::create('expense_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('expense_id')->constrained()->restrictOnDelete();
            $table->string('original_name');
            $table->string('path');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->foreignUuid('uploaded_by')->constrained('users');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_documents');

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            // MySQL let these composite indexes stand in for the indexes its
            // foreign keys need; give the keys their own before dropping.
            Schema::table('expenses', function (Blueprint $table) {
                $table->index('project_id');
                $table->index('organization_id');
            });
        }

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'status', 'category']);
            $table->dropIndex(['organization_id', 'status']);
            $table->dropConstrainedForeignId('grant_id');
            $table->dropConstrainedForeignId('decided_by');
            $table->dropConstrainedForeignId('paid_by');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['status', 'direct', 'decided_at', 'decision_note', 'payment_reference', 'cancelled_at']);
        });

        Schema::table('budget_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('budget_version_id');
        });

        Schema::dropIfExists('budget_versions');
    }
};
