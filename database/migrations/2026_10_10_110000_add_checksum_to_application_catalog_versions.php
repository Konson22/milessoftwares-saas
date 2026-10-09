<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Published catalog versions are immutable: re-importing a version is a
     * no-op when the checksum matches and an error when it does not.
     */
    public function up(): void
    {
        Schema::table('application_catalog_versions', function (Blueprint $table) {
            $table->char('checksum', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('application_catalog_versions', function (Blueprint $table) {
            $table->dropColumn('checksum');
        });
    }
};
