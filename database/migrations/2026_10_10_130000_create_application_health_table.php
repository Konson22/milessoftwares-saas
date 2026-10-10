<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The latest health report of each product or shared module. Reports
     * older than five minutes are shown as "unknown".
     */
    public function up(): void
    {
        Schema::create('application_health', function (Blueprint $table) {
            $table->foreignUuid('application_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('status', 16);
            $table->jsonb('components')->nullable();
            $table->string('version', 64)->nullable();
            $table->string('message', 500)->nullable();
            $table->timestampTz('reported_at');
            // When the status last changed, for "down since …".
            $table->timestampTz('status_since');
        });

        DB::statement("ALTER TABLE application_health ADD CONSTRAINT application_health_status_check CHECK (status IN ('ok', 'degraded', 'down'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('application_health');
    }
};
