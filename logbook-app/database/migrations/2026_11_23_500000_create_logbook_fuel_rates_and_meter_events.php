<?php

use App\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * ADR-0030 phase 4: fuel consumption rates per resource, with their
 * history, and meter events (a replaced or reset odometer or hour
 * meter). Both are append-only: a correction is a new row, so the rate an
 * entry was calculated with, and the reason a meter went back, stay on
 * record. A resource with no rate has no fuel calculation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('log_resource_fuel_rates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('log_resource_id');
            $table->string('fuel_type', 20);
            // distance: litres per 100 km; hours: litres per hour.
            $table->string('method', 10);
            $table->decimal('rate', 10, 3);
            $table->timestampTz('effective_from');
            $table->string('note', 500)->nullable();
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
            TenantSchema::key($table);
            TenantSchema::foreign($table, 'log_resource_id', 'log_resources');
            $table->index(['organization_id', 'log_resource_id', 'effective_from']);
        });

        Schema::create('log_resource_meter_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('log_resource_id');
            $table->string('measure', 12);
            $table->string('kind', 10);
            // The meter's reading right after the event (a new meter often starts at 0).
            $table->decimal('reading', 12, 1);
            $table->timestampTz('occurred_at');
            $table->string('reason', 500);
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
            TenantSchema::key($table);
            TenantSchema::foreign($table, 'log_resource_id', 'log_resources');
            $table->index(['organization_id', 'log_resource_id', 'measure', 'occurred_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE log_resource_fuel_rates
                    ADD CONSTRAINT log_resource_fuel_rates_method_check CHECK (method IN ('distance', 'hours')),
                    ADD CONSTRAINT log_resource_fuel_rates_rate_check CHECK (rate > 0),
                    ADD CONSTRAINT log_resource_fuel_rates_fuel_check CHECK (fuel_type IN ('diesel', 'petrol', 'other'));
                ALTER TABLE log_resource_meter_events
                    ADD CONSTRAINT log_resource_meter_events_measure_check CHECK (measure IN ('odometer', 'hour_meter')),
                    ADD CONSTRAINT log_resource_meter_events_kind_check CHECK (kind IN ('replaced', 'reset', 'corrected')),
                    ADD CONSTRAINT log_resource_meter_events_reading_check CHECK (reading >= 0);
                CREATE TRIGGER log_resource_fuel_rates_append_only BEFORE UPDATE OR DELETE ON log_resource_fuel_rates
                    FOR EACH ROW EXECUTE FUNCTION logbook_append_only();
                CREATE TRIGGER log_resource_meter_events_append_only BEFORE UPDATE OR DELETE ON log_resource_meter_events
                    FOR EACH ROW EXECUTE FUNCTION logbook_append_only();
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('log_resource_meter_events');
        Schema::dropIfExists('log_resource_fuel_rates');
    }
};
