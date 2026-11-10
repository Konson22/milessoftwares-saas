<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->char('code', 3);
            $table->string('name');
            $table->string('symbol', 8);
            $table->unsignedTinyInteger('decimals')->default(2);
            $table->boolean('active')->default(true);
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
        });

        Schema::create('station_currencies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->char('code', 3);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'branch_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('station_currencies');
        Schema::dropIfExists('currencies');
    }
};
