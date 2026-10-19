<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The application of the deployment that recorded the event: it is
        // delivered with that application's clients and as its source_app.
        // Null: the primary application (every event recorded before).
        Schema::table('platform_outbox', function (Blueprint $table) {
            $table->string('application', 32)->nullable()->after('organization_id');
        });
    }

    public function down(): void
    {
        Schema::table('platform_outbox', function (Blueprint $table) {
            $table->dropColumn('application');
        });
    }
};
