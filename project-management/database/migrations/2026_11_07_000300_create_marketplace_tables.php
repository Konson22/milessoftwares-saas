<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The funding marketplace: what an organization chooses to show donors. Each
 * table holds its own public copy, so publishing never opens a project, the
 * books or a document to anyone: the marketplace reads only these rows.
 * Additive; nothing existing changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        // One public profile per organization: draft, published or unpublished.
        Schema::create('organization_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->unique()->constrained()->restrictOnDelete();
            $table->string('status', 16)->default('draft');
            $table->string('summary', 300)->nullable();
            $table->text('description')->nullable();
            $table->text('mission')->nullable();
            $table->text('vision')->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('location')->nullable();
            $table->json('operating_areas')->nullable();
            $table->json('sectors')->nullable();
            $table->string('registration_number', 100)->nullable();
            $table->string('registration_authority')->nullable();
            $table->string('website')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 40)->nullable();
            $table->text('achievements')->nullable();
            $table->string('logo_path', 512)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'country_code']);
        });

        // A project seeking funding: at most one listing per project. The target
        // and confirmed amounts are what the organization states publicly, not
        // its internal budget or grant records.
        Schema::create('funding_listings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('project_id')->unique()->constrained()->restrictOnDelete();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->string('sector', 40)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('location')->nullable();
            $table->text('objectives')->nullable();
            $table->text('outcomes')->nullable();
            $table->date('start_on')->nullable();
            $table->date('end_on')->nullable();
            $table->char('currency', 3);
            $table->unsignedBigInteger('target_minor')->default(0);
            $table->unsignedBigInteger('confirmed_minor')->default(0);
            // draft | published | unpublished, apart from how funded it is.
            $table->string('publication_status', 16)->default('draft');
            // seeking | partially_funded | funded | closed
            $table->string('funding_status', 24)->default('seeking');
            $table->timestamp('published_at')->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();
            $table->index(['organization_id', 'publication_status']);
            $table->index(['publication_status', 'sector', 'country_code']);
        });

        // Documents an organization shows with its profile or a listing. Never a
        // confidential or archived one (App\Actions\Marketplace).
        Schema::create('marketplace_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('document_id')->constrained()->cascadeOnDelete();
            // profile | listing
            $table->string('subject_type', 16);
            $table->uuid('subject_id');
            $table->timestamps();
            $table->unique(['subject_type', 'subject_id', 'document_id'], 'marketplace_documents_subject_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_documents');
        Schema::dropIfExists('funding_listings');
        Schema::dropIfExists('organization_profiles');
    }
};
