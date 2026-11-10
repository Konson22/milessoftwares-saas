<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Connections. A donor's expression of interest in a funding listing (never a
 * grant, a commitment or confirmed funding); and, on the one partnership
 * model, inviting an organization already on the platform directly, and the
 * invited organization declining. Additive: existing partnerships keep
 * working as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Across two organizations, like partnerships: not organization-scoped.
        Schema::create('funding_interests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('donor_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUuid('funding_listing_id')->constrained()->restrictOnDelete();
            // The donor's representative, and how to reach them.
            $table->foreignUuid('contact_user_id')->constrained('users');
            $table->string('contact_name');
            $table->string('contact_email');
            $table->text('message');
            // submitted | under_review | accepted | declined
            $table->string('status', 16)->default('submitted');
            $table->text('response')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('responded_at')->nullable();
            $table->foreignUuid('responded_by')->nullable()->constrained('users');
            // The partnership the donor offered after an accepted interest.
            $table->foreignUuid('partnership_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
            $table->index(['donor_organization_id', 'status']);
        });

        Schema::table('partnerships', function (Blueprint $table) {
            // An organization already on the platform, invited by name: its
            // administrators accept in the app. Null for an invitation by email.
            $table->foreignUuid('invited_organization_id')->nullable()->after('beneficiary_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->timestamp('declined_at')->nullable()->after('accepted_by');
            $table->foreignUuid('declined_by')->nullable()->after('declined_at')->constrained('users');
            $table->index(['invited_organization_id', 'accepted_at']);
        });

        Schema::table('partnerships', function (Blueprint $table) {
            // An organization invited directly needs no address.
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('partnerships', function (Blueprint $table) {
            $table->dropIndex(['invited_organization_id', 'accepted_at']);
            $table->dropConstrainedForeignId('invited_organization_id');
            $table->dropConstrainedForeignId('declined_by');
            $table->dropColumn('declined_at');
        });
        Schema::dropIfExists('funding_interests');
    }
};
