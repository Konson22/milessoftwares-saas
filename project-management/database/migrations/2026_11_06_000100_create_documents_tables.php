<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An organization's own documents: policies, contracts, certificates. A new
     * file adds a version; versions are never changed. The foreign key from a
     * version includes organization_id, so the database refuses one that belongs
     * to another organization's document.
     */
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->string('title', 200);
            $table->string('category', 24);
            $table->text('description')->nullable();
            $table->boolean('confidential')->default(false);
            $table->unsignedInteger('current_version')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'archived_at', 'category']);
        });

        Schema::create('document_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('document_id');
            $table->unsignedInteger('version');
            $table->string('original_name', 255);
            $table->string('path', 512);
            $table->string('mime_type', 127);
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->string('note', 255)->nullable();
            $table->foreignUuid('uploaded_by')->nullable()->constrained('users');
            $table->timestamp('created_at');

            $table->unique(['document_id', 'version']);
            $table->foreign(['organization_id', 'document_id'])->references(['organization_id', 'id'])->on('documents');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_versions');
        Schema::dropIfExists('documents');
    }
};
