<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // This product's own settings for each organization, created when
        // the platform provisions the organization (App\Platform\SetUpWorkspace).
        Schema::create('workspaces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->unique();
            $table->string('slug', 63);
            $table->string('display_name');
            $table->char('currency', 3);
            $table->string('timezone', 64);
            $table->timestamps();
        });

        // Example tenant-owned records: replace with the product's own.
        Schema::create('records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->string('title');
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('records');
        Schema::dropIfExists('workspaces');
    }
};
