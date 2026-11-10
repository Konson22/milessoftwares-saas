<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->unsignedInteger('water_alert_mm')->nullable()->after('shared_pumps');
            $table->boolean('dispense_during_receiving')->default(true)->after('water_alert_mm');
            $table->unsignedSmallInteger('calibration_interval_days')->nullable()->after('dispense_during_receiving');
        });

        Schema::table('tanks', function (Blueprint $table) {
            $table->string('measurement_method', 16)->default('dipstick')->after('status');
            $table->boolean('water_alert')->default(false)->after('measurement_method');
        });

        Schema::create('tank_charts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->foreignUuid('tank_id')->constrained();
            $table->string('reference', 64);
            $table->unsignedInteger('version');
            $table->string('depth_unit', 8)->default('mm');
            $table->string('volume_unit', 8)->default('L');
            $table->timestamp('effective_from');
            $table->string('provider')->nullable();
            $table->string('document_note')->nullable();
            $table->string('status', 16);
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();
            $table->unique(['tank_id', 'version']);
        });

        Schema::table('tank_calibration_points', function (Blueprint $table) {
            $table->foreignUuid('chart_id')->nullable()->after('tank_id')->constrained('tank_charts');
            $table->dropUnique(['tank_id', 'depth_mm']);
            $table->unique(['chart_id', 'depth_mm']);
        });

        Schema::table('tank_measurements', function (Blueprint $table) {
            $table->foreignUuid('chart_id')->nullable()->after('tank_id')->constrained('tank_charts');
            $table->unsignedBigInteger('water_milli')->nullable()->after('water_mm');
            $table->string('evidence')->nullable()->after('notes');
            $table->uuid('correction_of')->nullable()->after('evidence');
        });

        Schema::table('fuel_receipts', function (Blueprint $table) {
            $table->timestamp('started_at')->nullable()->after('received_at');
            $table->timestamp('completed_at')->nullable()->after('started_at');
            $table->unsignedBigInteger('before_milli')->nullable()->after('completed_at');
            $table->unsignedBigInteger('after_milli')->nullable()->after('before_milli');
            $table->unsignedBigInteger('dispensed_milli')->nullable()->after('after_milli');
            $table->unsignedBigInteger('expected_milli')->nullable()->after('dispensed_milli');
            $table->bigInteger('receiving_variance_milli')->nullable()->after('expected_milli');
            $table->string('reconciliation', 16)->nullable()->after('receiving_variance_milli');
        });

        Schema::table('fuel_draws', function (Blueprint $table) {
            $table->string('status', 16)->default('recorded')->after('purpose');
            $table->string('disposition', 16)->nullable()->after('status');
            $table->unsignedBigInteger('returned_milli')->default(0)->after('quantity_milli');
            $table->foreignUuid('calibration_id')->nullable()->after('nozzle_id');
            $table->foreignUuid('approved_by')->nullable()->after('created_by')->constrained('users');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
        });

        Schema::create('pump_calibrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->foreignUuid('pump_id')->constrained();
            $table->foreignUuid('nozzle_id')->constrained();
            $table->date('calibrated_on');
            $table->string('technician');
            $table->string('reference')->nullable();
            $table->unsignedBigInteger('test_milli');
            $table->unsignedBigInteger('indicated_milli');
            $table->unsignedBigInteger('measured_milli');
            $table->bigInteger('difference_milli');
            $table->unsignedInteger('error_bps');
            $table->string('outcome', 16);
            $table->string('certificate_note')->nullable();
            $table->date('next_due')->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::table('fuel_draws', function (Blueprint $table) {
            $table->foreign('calibration_id')->references('id')->on('pump_calibrations');
        });

        Schema::create('nozzle_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('branch_id');
            $table->foreignUuid('shift_id')->nullable()->constrained();
            $table->foreignUuid('pump_id')->constrained();
            $table->foreignUuid('nozzle_id')->constrained();
            $table->string('type', 32);
            $table->unsignedBigInteger('previous_milli');
            $table->unsignedBigInteger('next_milli');
            $table->timestamp('effective_at');
            $table->string('technician');
            $table->string('evidence')->nullable();
            $table->foreignUuid('approved_by')->constrained('users');
            $table->timestamp('approved_at');
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::table('shift_exceptions', function (Blueprint $table) {
            $table->string('source_type', 32)->nullable()->after('handover_id');
            $table->uuid('source_id')->nullable()->after('source_type');
            $table->index(['shift_id', 'type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::table('shift_exceptions', function (Blueprint $table) {
            $table->dropIndex(['shift_id', 'type', 'source_id']);
            $table->dropColumn(['source_type', 'source_id']);
        });
        Schema::dropIfExists('nozzle_events');
        Schema::table('fuel_draws', function (Blueprint $table) {
            $table->dropForeign(['calibration_id']);
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['status', 'disposition', 'returned_milli', 'calibration_id', 'approved_at']);
        });
        Schema::dropIfExists('pump_calibrations');
        Schema::table('fuel_receipts', function (Blueprint $table) {
            $table->dropColumn(['started_at', 'completed_at', 'before_milli', 'after_milli', 'dispensed_milli', 'expected_milli', 'receiving_variance_milli', 'reconciliation']);
        });
        Schema::table('tank_measurements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('chart_id');
            $table->dropColumn(['water_milli', 'evidence', 'correction_of']);
        });
        Schema::table('tank_calibration_points', function (Blueprint $table) {
            $table->dropUnique(['chart_id', 'depth_mm']);
            $table->dropConstrainedForeignId('chart_id');
            $table->unique(['tank_id', 'depth_mm']);
        });
        Schema::dropIfExists('tank_charts');
        Schema::table('tanks', function (Blueprint $table) {
            $table->dropColumn(['measurement_method', 'water_alert']);
        });
        Schema::table('stations', function (Blueprint $table) {
            $table->dropColumn(['water_alert_mm', 'dispense_during_receiving', 'calibration_interval_days']);
        });
    }
};
