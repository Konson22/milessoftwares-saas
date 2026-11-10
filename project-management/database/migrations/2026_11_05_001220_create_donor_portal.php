<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The donor portal: people who act for a funder, what each grant shares with
 * them (nothing until it is turned on), report documents, and the
 * conversation on a grant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('funder_contacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('funder_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            // Someone acts for one funder in an organization.
            $table->unique(['organization_id', 'user_id']);
        });

        Schema::table('invitations', function (Blueprint $table) {
            $table->foreignUuid('funder_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::table('grants', function (Blueprint $table) {
            $table->boolean('portal_enabled')->default(false);
            $table->boolean('portal_budget')->default(false);
            $table->boolean('portal_spending')->default(false);
            $table->boolean('portal_progress')->default(false);
        });

        Schema::create('grant_report_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('grant_report_id')->constrained()->restrictOnDelete();
            $table->string('original_name');
            $table->string('path');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->foreignUuid('uploaded_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('grant_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('grant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained();
            $table->boolean('from_funder');
            $table->text('body');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['grant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grant_messages');
        Schema::dropIfExists('grant_report_documents');
        Schema::table('grants', function (Blueprint $table) {
            $table->dropColumn(['portal_enabled', 'portal_budget', 'portal_spending', 'portal_progress']);
        });
        Schema::table('invitations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('funder_id');
        });
        Schema::dropIfExists('funder_contacts');
    }
};
