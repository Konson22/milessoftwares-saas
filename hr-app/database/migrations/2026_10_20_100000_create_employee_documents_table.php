<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff documents (HR Phase H2). The file lives on the private
 * hr_documents disk under a path HR chooses ({organization}/{employee}/{id},
 * nothing from the upload); this row describes it. Removing a document
 * deletes the file and keeps the row, so the list shows who removed what.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('employee_id');
            $table->string('category', 16);
            $table->string('title', 160);
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->string('note', 255)->nullable();
            // The file: where it is, and what was uploaded.
            $table->string('path', 255);
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64);
            $table->foreignUuid('uploaded_by')->constrained('users');
            $table->timestamp('removed_at')->nullable();
            $table->foreignUuid('removed_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['organization_id', 'employee_id', 'removed_at']);
            $table->index(['organization_id', 'expires_on']);
            $table->foreign(['organization_id', 'employee_id'])->references(['organization_id', 'id'])->on('employees')->cascadeOnDelete();
        });

        // Which record an entry is about, when it is more than the employee
        // (a document that was downloaded or removed).
        Schema::table('hr_audit_log', function (Blueprint $table) {
            $table->uuid('subject_id')->nullable()->after('employee_id');
        });
    }

    public function down(): void
    {
        Schema::table('hr_audit_log', function (Blueprint $table) {
            $table->dropColumn('subject_id');
        });
        Schema::dropIfExists('employee_documents');
    }
};
