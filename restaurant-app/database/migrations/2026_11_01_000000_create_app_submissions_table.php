<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * What the mobile app did while the restaurant could not be reached, as it
 * was applied here: one row per submission id (made on the phone), with
 * the answer it got. The same submission sent again (the answer was lost
 * on the way back) gets that answer, and is never applied twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('kind', 32);
            $table->json('result');
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('created_at');
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_submissions');
    }
};
