<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Gap-free running numbers per organization (App\Support\DocumentNumbers).
        Schema::create('document_numbers', function (Blueprint $table) {
            $table->uuid('organization_id');
            $table->string('type', 32);
            $table->unsignedBigInteger('last_number');
            $table->primary(['organization_id', 'type']);
        });

        // Who shipments are carried for and billed to.
        Schema::create('customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->string('name');
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('drivers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->string('name');
            $table->string('phone', 32)->nullable();
            $table->string('licence_number', 64)->nullable();
            $table->date('licence_expires_on')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // The fleet. Whether a vehicle is out on a trip comes from its trips;
        // the status says whether it may be sent at all.
        Schema::create('vehicles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('registration', 32);
            $table->string('kind', 16);
            $table->string('description')->nullable();
            $table->unsignedInteger('capacity_kg')->nullable();
            $table->unsignedInteger('odometer_km')->default(0);
            $table->string('status', 16);
            $table->timestamps();
            $table->unique(['organization_id', 'registration']);
        });

        Schema::create('maintenance_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->foreignUuid('vehicle_id')->constrained();
            $table->date('performed_on');
            $table->string('description');
            $table->string('garage')->nullable();
            $table->unsignedInteger('odometer_km')->nullable();
            $table->unsignedBigInteger('cost_minor');
            $table->foreignUuid('recorded_by')->constrained('users');
            $table->timestamps();
        });

        // A vehicle and driver sent out with shipments: planned, dispatched
        // (in progress), then completed or cancelled.
        Schema::create('trips', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('number', 32);
            $table->foreignUuid('vehicle_id')->constrained();
            $table->foreignUuid('driver_id')->constrained();
            $table->string('route')->nullable();
            $table->date('planned_on');
            $table->string('status', 16);
            $table->unsignedInteger('start_odometer_km')->nullable();
            $table->unsignedInteger('end_odometer_km')->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'status']);
        });

        // What a trip cost: fuel, tolls, allowances...
        Schema::create('trip_costs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('trip_id')->constrained();
            $table->string('category', 16);
            $table->string('description')->nullable();
            $table->unsignedBigInteger('amount_minor');
            $table->date('incurred_on');
            $table->foreignUuid('recorded_by')->constrained('users');
            $table->timestamps();
        });

        // Customer invoices for delivered shipments, paid in one or more payments.
        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('number', 32);
            $table->foreignUuid('customer_id')->constrained();
            $table->char('currency', 3);
            $table->unsignedBigInteger('total_minor');
            $table->unsignedBigInteger('paid_minor')->default(0);
            $table->string('status', 16);
            $table->date('issued_on');
            $table->date('due_on')->nullable();
            $table->foreignUuid('issued_by')->constrained('users');
            $table->foreignUuid('voided_by')->nullable()->constrained('users');
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('shipments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('number', 32);
            $table->foreignUuid('customer_id')->constrained();
            $table->string('status', 16);
            $table->string('pickup_address');
            $table->string('delivery_address');
            $table->string('recipient_name');
            $table->string('recipient_phone', 32)->nullable();
            $table->string('goods');
            $table->unsignedSmallInteger('packages')->default(1);
            $table->decimal('weight_kg', 10, 2)->nullable();
            $table->unsignedBigInteger('charge_minor');
            $table->date('pickup_on')->nullable();
            $table->foreignUuid('trip_id')->nullable()->constrained();
            $table->foreignUuid('invoice_id')->nullable()->constrained();
            $table->foreignUuid('booked_by')->constrained('users');
            $table->timestamp('delivered_at')->nullable();
            $table->string('received_by')->nullable();
            $table->string('delivery_note')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'status']);
        });

        // Where a shipment stands, step by step: its tracking history.
        Schema::create('shipment_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('shipment_id')->constrained();
            $table->string('status', 16);
            $table->string('location')->nullable();
            $table->string('note')->nullable();
            $table->foreignUuid('recorded_by')->constrained('users');
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['shipment_id', 'occurred_at']);
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('invoice_id')->constrained();
            $table->foreignUuid('shipment_id')->nullable()->constrained();
            $table->string('description');
            $table->unsignedBigInteger('amount_minor');
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->foreignUuid('invoice_id')->constrained();
            $table->string('number', 32);
            $table->unsignedBigInteger('amount_minor');
            $table->string('method', 16);
            $table->string('reference', 128)->nullable();
            $table->date('paid_on');
            $table->foreignUuid('received_by')->constrained('users');
            $table->timestamps();
            $table->unique(['organization_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('shipment_events');
        Schema::dropIfExists('shipments');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('trip_costs');
        Schema::dropIfExists('trips');
        Schema::dropIfExists('maintenance_records');
        Schema::dropIfExists('vehicles');
        Schema::dropIfExists('drivers');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('document_numbers');
    }
};
