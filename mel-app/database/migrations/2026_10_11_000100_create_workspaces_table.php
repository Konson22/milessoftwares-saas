<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // M&E's own settings for each organization, created when the
        // platform provisions the organization (App\Platform\SetUpWorkspace).
        Schema::create('workspaces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->unique();
            $table->string('display_name');
            $table->char('currency', 3);
            $table->string('timezone', 64);
            // Separation of duties inside a submission's review.
            $table->boolean('verifier_must_differ')->default(true);
            $table->boolean('publisher_must_differ')->default(false);
            // Performance bands: at or above on_track_percent of target is
            // "on track", at or above attention_percent "needs attention",
            // below it "off track". Per indicator, never combined.
            $table->unsignedSmallInteger('on_track_percent')->default(90);
            $table->unsignedSmallInteger('attention_percent')->default(70);
            $table->timestamps();
        });

        // Who has opened M&E in this organization (users are platform
        // accounts shared by every organization). People pickers and
        // person ids in requests are limited to these.
        Schema::create('workspace_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('user_id')->constrained('users');
            $table->timestampTz('last_seen_at');
            $table->timestamps();

            $table->unique(['organization_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_members');
        Schema::dropIfExists('workspaces');
    }
};
