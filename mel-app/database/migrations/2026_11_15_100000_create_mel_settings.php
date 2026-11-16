<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each organization's M&E settings (App\Mel\Models\Settings), made when the
 * platform provisions the organization (App\Platform\SetUpWorkspace). Tables
 * keep the mel_ and collect_ prefixes they had inside Projects, so the
 * schema is the same one (ADR-0019 §4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mel_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // The platform organization's id; organizations live in Central.
            $table->uuid('organization_id')->unique();
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
    }

    public function down(): void
    {
        Schema::dropIfExists('mel_settings');
    }
};
