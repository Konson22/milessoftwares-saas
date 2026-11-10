<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Messages from the public website. They belong to no organization:
     * the sender may not have an account.
     */
    public function up(): void
    {
        Schema::create('inquiries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // contact | demo
            $table->string('kind', 16);
            $table->string('name');
            $table->string('email');
            $table->string('organization_name');
            $table->string('organization_type', 64);
            $table->string('country');
            $table->string('subject')->nullable();
            $table->string('job_title')->nullable();
            $table->string('organization_size', 32)->nullable();
            $table->string('interest', 64)->nullable();
            $table->text('message');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiries');
    }
};
