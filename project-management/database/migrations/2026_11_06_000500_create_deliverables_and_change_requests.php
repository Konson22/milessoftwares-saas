<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a project promises to hand over, and requests to change the project
 * once it is under way. Neither is deleted: both keep their history.
 *
 * Also gives programmes the link to their organization that they were
 * created without.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programmes', function (Blueprint $table) {
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
        });

        Schema::create('deliverables', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('project_id')->constrained();
            $table->foreignUuid('milestone_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignUuid('owner_id')->nullable()->constrained('people');
            $table->date('due_on')->nullable();
            $table->string('status', 20)->default('planned');
            $table->text('delivery_note')->nullable();
            $table->foreignUuid('delivered_by')->nullable()->constrained('users');
            $table->timestamp('delivered_at')->nullable();
            $table->foreignUuid('accepted_by')->nullable()->constrained('users');
            $table->timestamp('accepted_at')->nullable();
            $table->text('returned_reason')->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['organization_id', 'project_id', 'status']);
        });

        Schema::create('change_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('project_id')->constrained();
            $table->unsignedInteger('number');
            $table->string('title');
            $table->text('description');
            $table->date('proposed_due_on')->nullable();
            $table->date('previous_due_on')->nullable();
            $table->bigInteger('budget_change_minor')->nullable();
            // The draft an approved budget change started; a draft can be discarded.
            $table->foreignUuid('budget_version_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('submitted');
            $table->foreignUuid('raised_by')->constrained('users');
            $table->foreignUuid('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'number']);
            $table->index(['organization_id', 'project_id', 'status']);
        });

        // As for financial records: the database refuses another organization's project.
        if (DB::getDriverName() === 'pgsql') {
            Schema::table('projects', function (Blueprint $table) {
                $table->unique(['organization_id', 'id'], 'projects_tenant_key');
            });
            foreach (['deliverables', 'change_requests'] as $child) {
                Schema::table($child, function (Blueprint $table) use ($child) {
                    $table->foreign(['organization_id', 'project_id'], "{$child}_project_id_tenant_fk")->references(['organization_id', 'id'])->on('projects');
                });
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('change_requests');
        Schema::dropIfExists('deliverables');

        if (DB::getDriverName() === 'pgsql') {
            Schema::table('projects', function (Blueprint $table) {
                $table->dropUnique('projects_tenant_key');
            });
        }

        Schema::table('programmes', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
        });
    }
};
