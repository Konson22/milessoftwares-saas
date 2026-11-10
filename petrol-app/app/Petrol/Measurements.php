<?php

namespace App\Petrol;

use App\Models\Shift;
use App\Models\ShiftException;
use App\Models\Station;
use App\Models\StockMovement;
use App\Models\Tank;
use App\Models\TankChart;
use App\Models\TankMeasurement;
use App\Models\User;
use App\Support\BusinessDate;
use App\Support\Money;
use App\Support\Volume;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Miles\PlatformClient\Events\Outbox;

/**
 * A dip is evidence. It stores the book quantity beside the physical
 * quantity and does not post a stock movement.
 */
class Measurements
{
    public function __construct(
        private readonly Calibration $charts,
        private readonly ShiftExceptions $exceptions,
        private readonly Audit $audit,
        private readonly Outbox $outbox,
    ) {}

    /**
     * @param  array{method: string, litres: ?string, depth_mm: ?int, water_mm: ?int, temperature_c: ?string, notes: ?string, evidence: ?string}  $input
     */
    public function record(Tank $tank, array $input, User $by, ?string $shiftId): TankMeasurement
    {
        return DB::transaction(function () use ($tank, $input, $by, $shiftId) {
            $locked = Tank::query()->whereKey($tank->id)->lockForUpdate()->firstOrFail();
            $chart = null;
            $method = $input['method'];
            if ($method === TankMeasurement::DIPSTICK) {
                $chart = TankChart::query()->where('tank_id', $locked->id)->where('status', TankChart::ACTIVE)->first();
                if ($chart === null || $input['depth_mm'] === null) {
                    throw ValidationException::withMessages(['depth_mm' => 'A dipstick reading needs a depth and an active calibration chart.']);
                }
                $measured = $this->charts->volumeAt($chart, $input['depth_mm']);
            } else {
                if ($input['litres'] === null || $input['litres'] === '') {
                    throw ValidationException::withMessages(['litres' => 'Enter the measured litres.']);
                }
                try {
                    $measured = Volume::toMilli($input['litres']);
                } catch (\InvalidArgumentException $e) {
                    throw ValidationException::withMessages(['litres' => $e->getMessage()]);
                }
            }
            if ($measured < 0 || $measured > $locked->capacity_milli) {
                throw ValidationException::withMessages(['litres' => 'The reading is outside the tank capacity.']);
            }

            $waterMilli = null;
            if ($input['water_mm'] !== null && $chart !== null) {
                try {
                    $waterMilli = $this->charts->volumeAt($chart, $input['water_mm']);
                } catch (ValidationException) {
                    $waterMilli = null;
                }
            }

            $measurement = TankMeasurement::query()->create([
                'branch_id' => $locked->branch_id,
                'tank_id' => $locked->id,
                'chart_id' => $chart?->id,
                'shift_id' => $shiftId,
                'method' => $method,
                'depth_mm' => $input['depth_mm'],
                'temperature_c' => $input['temperature_c'],
                'water_mm' => $input['water_mm'],
                'water_milli' => $waterMilli,
                'measured_milli' => $measured,
                'book_milli' => $locked->quantity_milli,
                'variance_milli' => $measured - $locked->quantity_milli,
                'status' => TankMeasurement::RECORDED,
                'measured_by' => $by->id,
                'measured_at' => now(),
                'notes' => $input['notes'],
                'evidence' => $input['evidence'],
            ]);
            $this->flagWater($locked, $input['water_mm'], $by);
            $this->audit->record($by, 'tank.measured', $measurement, null, [
                'measured_milli' => $measured,
                'book_milli' => $locked->quantity_milli,
            ], $locked->branch_id);

            return $measurement;
        });
    }

    /**
     * @param  array{method: string, litres: ?string, depth_mm: ?int, water_mm: ?int, temperature_c: ?string, notes: ?string, evidence: ?string}  $input
     */
    public function correct(TankMeasurement $measurement, array $input, User $by): TankMeasurement
    {
        $tank = Tank::query()->whereKey($measurement->tank_id)->firstOrFail();
        $created = $this->record($tank, $input, $by, $measurement->shift_id);
        $created->forceFill(['correction_of' => $measurement->id, 'notes' => trim(($input['notes'] ?? '').' Correction of an earlier reading')])->save();

        return $created;
    }

    public function verify(TankMeasurement $measurement, User $by): TankMeasurement
    {
        if ($measurement->measured_by === $by->id) {
            throw ValidationException::withMessages(['measurement' => 'Someone else has to verify a reading you recorded.']);
        }
        if ($measurement->status !== TankMeasurement::RECORDED) {
            throw ValidationException::withMessages(['measurement' => 'This reading is not waiting for verification.']);
        }
        $measurement->forceFill([
            'status' => TankMeasurement::VERIFIED,
            'verified_by' => $by->id,
            'verified_at' => now(),
        ])->save();
        $this->audit->record($by, 'tank.measurement.verified', $measurement, null, ['status' => TankMeasurement::VERIFIED], $measurement->branch_id);

        return $measurement;
    }

    /**
     * The only path from a dip to the stock ledger. The dip itself stays as recorded.
     */
    public function postAdjustment(TankMeasurement $measurement, User $by): TankMeasurement
    {
        return DB::transaction(function () use ($measurement, $by) {
            $locked = TankMeasurement::query()->whereKey($measurement->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== TankMeasurement::VERIFIED) {
                throw ValidationException::withMessages(['measurement' => 'Verify the reading before posting it to stock.']);
            }
            if ($locked->measured_by === $by->id) {
                throw ValidationException::withMessages(['measurement' => 'Someone else has to post an adjustment for a reading you took.']);
            }
            if (StockMovement::query()->where('idempotency_key', 'count:'.$locked->id)->exists()) {
                return $locked;
            }
            $tank = Tank::query()->whereKey($locked->tank_id)->lockForUpdate()->firstOrFail();
            $variance = $locked->measured_milli - $tank->quantity_milli;
            if ($variance !== 0) {
                $tank->quantity_milli += $variance;
                $tank->save();
                $movement = StockMovement::query()->create([
                    'branch_id' => $tank->branch_id,
                    'tank_id' => $tank->id,
                    'kind' => StockMovement::COUNT,
                    'quantity_milli' => $variance,
                    'unit_cost_minor' => $tank->average_cost_minor,
                    'reference_type' => TankMeasurement::class,
                    'reference_id' => $locked->id,
                    'idempotency_key' => 'count:'.$locked->id,
                    'notes' => 'Approved dip adjustment',
                    'created_by' => $by->id,
                    'occurred_at' => now(),
                ]);
                $amount = Volume::amount($variance, $tank->average_cost_minor);
                if ($amount !== 0) {
                    $this->outbox->record('finance', 'finance.stock.adjusted', 1, 'stock_adjustment:count:'.$movement->id, [
                        'reference' => $tank->code,
                        'branch_id' => $tank->branch_id,
                        'currency' => Money::currency(),
                        'adjusted_on' => BusinessDate::today(),
                        'kind' => 'count',
                        'amount_minor' => $amount,
                        'reason' => 'Approved dip '.$tank->name,
                        'lines' => [[
                            'description' => $tank->name,
                            'quantity' => (float) Volume::toLitres($variance),
                            'amount_minor' => $amount,
                        ]],
                    ]);
                }
            }
            $locked->forceFill([
                'status' => TankMeasurement::APPROVED,
                'approved_by' => $by->id,
                'approved_at' => now(),
            ])->save();

            return $locked;
        });
    }

    public function clearWater(Tank $tank, User $by, string $note): Tank
    {
        $tank->forceFill(['water_alert' => false])->save();
        $this->audit->record($by, 'tank.water.cleared', $tank, ['water_alert' => true], ['water_alert' => false, 'note' => $note], $tank->branch_id);

        return $tank;
    }

    private function flagWater(Tank $tank, ?int $waterMm, User $by): void
    {
        if ($waterMm === null) {
            return;
        }
        $limit = Station::query()->where('branch_id', $tank->branch_id)->value('water_alert_mm');
        if ($limit === null || $waterMm < (int) $limit) {
            return;
        }
        $tank->forceFill(['water_alert' => true])->save();
        $shift = Shift::query()->where('branch_id', $tank->branch_id)->where('status', Shift::OPEN)->first();
        if ($shift === null) {
            return;
        }
        $this->exceptions->open(
            $shift,
            null,
            null,
            'water',
            $tank->code.' has '.$waterMm.' mm of water, above the station limit of '.$limit.' mm. Dispensing is held until a supervisor clears the tank.',
            ShiftException::CRITICAL,
            $by,
            'tank',
            $tank->id,
        );
    }
}
