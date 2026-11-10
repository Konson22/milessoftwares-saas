<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A donor organization and a beneficiary or implementer it works with. Starts
 * as an invitation with only the donor's details for the new organization. Once
 * accepted, it names the beneficiary and the funder record that stands for the
 * donor in the beneficiary's own directory: what that funder's grants share is
 * all the donor sees.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partnerships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('donor_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUuid('beneficiary_organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->foreignUuid('funder_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('label', 16);
            $table->string('name');
            $table->string('email');
            $table->char('currency', 3);
            $table->string('timezone');
            $table->char('country_code', 2)->nullable();
            $table->string('token_hash', 64)->nullable()->unique();
            $table->foreignUuid('invited_by')->constrained('users');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->foreignUuid('accepted_by')->nullable()->constrained('users');
            $table->timestamps();
            // Named: the generated name is over MariaDB's 64 characters.
            $table->unique(['donor_organization_id', 'beneficiary_organization_id'], 'partnerships_donor_beneficiary_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partnerships');
    }
};
