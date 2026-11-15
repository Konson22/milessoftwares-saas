<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M&E and Data Collection are part of Projects (ADR-0013). Their tables are
 * prefixed mel_ and collect_; this one holds each organization's M&E
 * settings, made the first time M&E is opened (App\Mel\Models\Settings).
 *
 * Projects no longer sends M&E project snapshots (the separate M&E app is
 * gone), so their bookkeeping table goes, and milestones get the tenant key
 * that M&E results point at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mel_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->unique()->constrained()->restrictOnDelete();
            $table->string('display_name');
            $table->char('currency', 3);
            $table->string('timezone', 64);
            // Who may verify and publish (ADR-0011 §5).
            $table->boolean('verifier_must_differ')->default(true);
            $table->boolean('publisher_must_differ')->default(false);
            // Performance bands, as % of target.
            $table->unsignedSmallInteger('on_track_percent')->default(90);
            $table->unsignedSmallInteger('attention_percent')->default(70);
            $table->timestamps();
        });

        Schema::dropIfExists('mel_project_snapshots');

        if (DB::getDriverName() === 'pgsql') {
            Schema::table('milestones', fn (Blueprint $table) => $table->unique(['organization_id', 'id'], 'milestones_tenant_key'));
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            Schema::table('milestones', fn (Blueprint $table) => $table->dropUnique('milestones_tenant_key'));
        }

        Schema::dropIfExists('mel_settings');
    }
};
