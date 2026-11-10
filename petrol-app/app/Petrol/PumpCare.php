<?php

namespace App\Petrol;

use App\Models\FuelDraw;
use App\Models\Nozzle;
use App\Models\NozzleEvent;
use App\Models\PumpCalibration;
use App\Models\Shift;
use App\Models\Station;
use App\Models\StockMovement;
use App\Models\Tank;
use App\Models\User;
use App\Support\Volume;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pump proving and meter equipment events. Test fuel is a stock movement,
 * never a customer sale. Returning it posts one addition.
 */
class PumpCare
{
    public function __construct(private readonly Audit $audit) {}

    /**
     * @param  array{test: string, indicated: string, measured: string, calibrated_on: string, technician: string, reference: ?string, outcome: string, certificate_note: ?string, next_due: ?string}  $input
     */
    public function calibrate(Nozzle $nozzle, array $input, User $by): PumpCalibration
    {
        try {
            $test = Volume::toMilli($input['test']);
            $indicated = Volume::toMilli($input['indicated']);
            $measured = Volume::toMilli($input['measured']);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['measured' => $e->getMessage()]);
        }
        if ($test < 1 || $indicated < 1 || $measured < 0) {
            throw ValidationException::withMessages(['measured' => 'Enter the meter quantity and the proving-can quantity.']);
        }
        $difference = $measured - $indicated;
        $error = (int) intdiv(abs($difference) * 10000, $indicated);
        $station = Station::query()->where('branch_id', $nozzle->branch_id)->first();
        $next = $input['next_due'];
        if ($next === null && $station?->calibration_interval_days) {
            $next = now()->addDays((int) $station->calibration_interval_days)->toDateString();
        }
        $row = PumpCalibration::query()->create([
            'branch_id' => $nozzle->branch_id,
            'pump_id' => $nozzle->pump_id,
            'nozzle_id' => $nozzle->id,
            'calibrated_on' => $input['calibrated_on'],
            'technician' => $input['technician'],
            'reference' => $input['reference'],
            'test_milli' => $test,
            'indicated_milli' => $indicated,
            'measured_milli' => $measured,
            'difference_milli' => $difference,
            'error_bps' => $error,
            'outcome' => $input['outcome'],
            'certificate_note' => $input['certificate_note'],
            'next_due' => $next,
            'created_by' => $by->id,
        ]);
        $this->audit->record($by, 'pump.calibrated', $row, null, [
            'difference_milli' => $difference,
            'outcome' => $row->outcome,
        ], $nozzle->branch_id);

        return $row;
    }

    public function testDraw(Nozzle $nozzle, Tank $tank, string $litres, string $purpose, User $by): FuelDraw
    {
        if ($tank->branch_id !== $nozzle->branch_id || $tank->fuel_product_id !== $nozzle->fuel_product_id) {
            throw ValidationException::withMessages(['tank_id' => 'Choose the tank this nozzle draws from.']);
        }
        try {
            $milli = Volume::toMilli($litres);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['litres' => $e->getMessage()]);
        }
        if ($milli < 1) {
            throw ValidationException::withMessages(['litres' => 'Enter the test quantity.']);
        }
        $shift = Shift::query()->where('branch_id', $nozzle->branch_id)->where('status', Shift::OPEN)->first();
        $draw = FuelDraw::query()->create([
            'branch_id' => $nozzle->branch_id,
            'tank_id' => $tank->id,
            'nozzle_id' => $nozzle->id,
            'shift_id' => $shift?->id,
            'quantity_milli' => $milli,
            'returned_milli' => 0,
            'purpose' => $purpose === '' ? FuelDraw::TEST : $purpose,
            'status' => FuelDraw::RECORDED,
            'disposition' => 'held',
            'created_by' => $by->id,
            'occurred_at' => now(),
        ]);
        $this->audit->record($by, 'pump.test.recorded', $draw, null, ['quantity_milli' => $milli], $nozzle->branch_id);

        return $draw;
    }

    public function approveDraw(FuelDraw $draw, User $by): FuelDraw
    {
        return DB::transaction(function () use ($draw, $by) {
            $locked = FuelDraw::query()->whereKey($draw->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === FuelDraw::APPROVED || $locked->status === FuelDraw::RETURNED) {
                return $locked;
            }
            if ($locked->created_by === $by->id) {
                throw ValidationException::withMessages(['draw' => 'Someone else has to approve a test you recorded.']);
            }
            if (StockMovement::query()->where('idempotency_key', 'draw:'.$locked->id)->exists()) {
                return $locked;
            }
            $tank = Tank::query()->whereKey($locked->tank_id)->lockForUpdate()->firstOrFail();
            if ($tank->quantity_milli < $locked->quantity_milli) {
                throw ValidationException::withMessages(['draw' => 'The tank does not hold the test quantity.']);
            }
            $tank->quantity_milli -= $locked->quantity_milli;
            $tank->save();
            StockMovement::query()->create([
                'branch_id' => $tank->branch_id,
                'tank_id' => $tank->id,
                'kind' => StockMovement::TEST,
                'quantity_milli' => -$locked->quantity_milli,
                'unit_cost_minor' => $tank->average_cost_minor,
                'reference_type' => FuelDraw::class,
                'reference_id' => $locked->id,
                'idempotency_key' => 'draw:'.$locked->id,
                'notes' => 'Pump test',
                'created_by' => $by->id,
                'occurred_at' => $locked->occurred_at,
            ]);
            $locked->forceFill([
                'status' => FuelDraw::APPROVED,
                'approved_by' => $by->id,
                'approved_at' => now(),
            ])->save();
            $this->audit->record($by, 'pump.test.approved', $locked, null, ['quantity_milli' => $locked->quantity_milli], $locked->branch_id);

            return $locked;
        });
    }

    public function returnDraw(FuelDraw $draw, User $by): FuelDraw
    {
        return DB::transaction(function () use ($draw, $by) {
            $locked = FuelDraw::query()->whereKey($draw->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === FuelDraw::RETURNED) {
                return $locked;
            }
            if ($locked->status !== FuelDraw::APPROVED) {
                throw ValidationException::withMessages(['draw' => 'Approve the test before returning the fuel.']);
            }
            if (StockMovement::query()->where('idempotency_key', 'draw:'.$locked->id.':return')->exists()) {
                return $locked;
            }
            $tank = Tank::query()->whereKey($locked->tank_id)->lockForUpdate()->firstOrFail();
            $tank->quantity_milli += $locked->quantity_milli;
            $tank->save();
            StockMovement::query()->create([
                'branch_id' => $tank->branch_id,
                'tank_id' => $tank->id,
                'kind' => StockMovement::TEST,
                'quantity_milli' => $locked->quantity_milli,
                'unit_cost_minor' => $tank->average_cost_minor,
                'reference_type' => FuelDraw::class,
                'reference_id' => $locked->id,
                'idempotency_key' => 'draw:'.$locked->id.':return',
                'notes' => 'Test fuel returned to the tank',
                'created_by' => $by->id,
                'occurred_at' => now(),
            ]);
            $locked->forceFill([
                'status' => FuelDraw::RETURNED,
                'returned_milli' => $locked->quantity_milli,
                'disposition' => 'returned',
            ])->save();
            $this->audit->record($by, 'pump.test.returned', $locked, null, ['returned_milli' => $locked->quantity_milli], $locked->branch_id);

            return $locked;
        });
    }

    /**
     * @param  array{type: string, previous: string, next: string, technician: string, evidence: ?string}  $input
     */
    public function equipment(Nozzle $nozzle, array $input, User $by): NozzleEvent
    {
        try {
            $previous = Volume::toMilli($input['previous']);
            $next = Volume::toMilli($input['next']);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['previous' => $e->getMessage()]);
        }
        $shift = Shift::query()->where('branch_id', $nozzle->branch_id)->where('status', Shift::OPEN)->first();
        $event = NozzleEvent::query()->create([
            'branch_id' => $nozzle->branch_id,
            'shift_id' => $shift?->id,
            'pump_id' => $nozzle->pump_id,
            'nozzle_id' => $nozzle->id,
            'type' => $input['type'],
            'previous_milli' => $previous,
            'next_milli' => $next,
            'effective_at' => now(),
            'technician' => $input['technician'],
            'evidence' => $input['evidence'],
            'approved_by' => $by->id,
            'approved_at' => now(),
            'created_by' => $by->id,
        ]);
        $this->audit->record($by, 'nozzle.event', $event, null, ['type' => $event->type], $nozzle->branch_id);

        return $event;
    }
}
