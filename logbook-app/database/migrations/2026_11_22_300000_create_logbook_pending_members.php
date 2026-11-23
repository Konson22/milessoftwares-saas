<?php

use App\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Logbooks chosen for someone invited by email, before they have an
 * account here: they become members of them the first time they open
 * Logbook (App\Http\Middleware\RememberMember). Dropped when the
 * invitation is cancelled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logbook_pending_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            // Lower case: matched to the person's sign-in address.
            $table->string('email');
            $table->uuid('invitation_id')->nullable();
            $table->uuid('logbook_id');
            $table->foreignUuid('granted_by')->constrained('users');
            $table->timestamps();
            TenantSchema::key($table);
            TenantSchema::foreign($table, 'logbook_id', 'logbooks');
            $table->unique(['organization_id', 'email', 'logbook_id']);
            $table->index(['organization_id', 'invitation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logbook_pending_members');
    }
};
