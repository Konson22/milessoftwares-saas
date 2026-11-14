<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The last snapshot of each project sent to M&E (App\Support\MelEvents):
 * its fingerprint and the outbox row that carried it, so only a project
 * that changed, or whose last snapshot was skipped, is sent again. Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mel_project_snapshots', function (Blueprint $table) {
            $table->foreignUuid('project_id')->primary()->constrained()->cascadeOnDelete();
            $table->uuid('organization_id')->index();
            $table->char('hash', 64);
            $table->uuid('outbox_id');
            $table->timestamp('sent_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mel_project_snapshots');
    }
};
