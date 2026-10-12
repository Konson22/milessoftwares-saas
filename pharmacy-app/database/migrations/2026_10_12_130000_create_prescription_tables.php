<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Patient data is kept to what dispensing needs: a name and, if
        // given, an age. Health details stay on the paper prescription.
        Schema::create('prescriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->uuid('branch_id');
            $table->string('number', 32);
            $table->string('patient_name');
            $table->unsignedSmallInteger('patient_age')->nullable();
            $table->string('prescriber_name');
            $table->string('prescriber_registration', 64)->nullable();
            $table->date('issued_on');
            // recorded → verified → dispensed, or rejected / cancelled
            $table->string('status', 16);
            $table->string('notes', 1000)->nullable();
            $table->foreignUuid('recorded_by')->constrained('users');
            $table->foreignUuid('verified_by')->nullable()->constrained('users');
            $table->timestamp('verified_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();
            $table->foreignUuid('dispensed_sale_id')->nullable()->constrained('sales');
            $table->timestamps();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'branch_id', 'status']);
        });

        Schema::create('prescription_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('prescription_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('medicine_id')->constrained();
            $table->integer('quantity');
            $table->string('directions', 255);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prescription_items');
        Schema::dropIfExists('prescriptions');
    }
};
