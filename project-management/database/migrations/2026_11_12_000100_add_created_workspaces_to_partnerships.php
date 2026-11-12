<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A third way in: the donor creates the workspace of the organization it funds
 * (on the platform, which holds it until the person named claims it). The
 * partnership remembers the platform's claim and the new organization's id;
 * it connects the first time that organization opens Projects. Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partnerships', function (Blueprint $table) {
            // The platform's claim, for resending the link or withdrawing the workspace.
            $table->uuid('platform_claim_id')->nullable()->unique()->after('invited_organization_id');
            // The platform organization made for them. Not a foreign key: it is
            // mirrored here only when it first opens Projects.
            $table->uuid('created_organization_id')->nullable()->index()->after('platform_claim_id');
            $table->string('created_slug', 63)->nullable()->after('created_organization_id');
        });
    }

    public function down(): void
    {
        Schema::table('partnerships', function (Blueprint $table) {
            $table->dropUnique(['platform_claim_id']);
            $table->dropIndex(['created_organization_id']);
            $table->dropColumn(['platform_claim_id', 'created_organization_id', 'created_slug']);
        });
    }
};
