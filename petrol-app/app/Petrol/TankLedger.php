<?php

namespace App\Petrol;

use App\Models\FuelReceipt;
use App\Models\FuelSale;
use App\Models\StockMovement;
use App\Models\Tank;
use App\Models\TankMeasurement;
use App\Models\User;
use App\Support\BusinessDate;
use App\Support\Money;
use App\Support\Volume;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Miles\PlatformClient\Events\Outbox;

/**
 * The tank cache and its movement ledger, updated together under a row lock.
 * A sale cannot take the tank below zero.
 */
class TankLedger
{
    public function __construct(
        private readonly Outbox $outbox,
        private readonly Audit $audit,
    ) {}

    public function opening(Tank $tank, int $milli, int $unitCost, User $by): void
    {
        DB::transaction(function () use ($tank, $milli, $unitCost, $by) {
            $locked = $this->lock($tank);

            if ($locked->quantity_milli !== 0 || StockMovement::query()->where('tank_id', $locked->id)->where('kind', StockMovement::OPENING)->exists()) {
                throw ValidationException::withMessages(['litres' => 'Opening stock is already recorded for this tank.']);
            }

            $this->guardCapacity($locked, $milli);
            $this->add($locked, $milli, $unitCost, StockMovement::OPENING, Tank::class, $locked->id, 'opening:'.$locked->id, 'Opening stock', $by);

            $cost = Volume::amount($milli, $unitCost);
            $this->outbox->record('finance', 'finance.stock.adjusted', 1, 'stock_adjustment:opening:'.$locked->id, [
                'reference' => $locked->code,
                'branch_id' => $locked->branch_id,
                'currency' => Money::currency(),
                'adjusted_on' => BusinessDate::today(),
                'kind' => 'opening',
                'amount_minor' => $cost,
                'reason' => 'Opening stock '.$locked->name,
                'lines' => [[
                    'description' => $locked->name,
                    'quantity' => (float) Volume::toLitres($milli),
                    'amount_minor' => $cost,
                ]],
            ]);
            $this->audit->record($by, 'tank.opening_stock', $locked, ['quantity_milli' => 0], [
                'quantity_milli' => $milli,
                'unit_cost_minor' => $unitCost,
            ], $locked->branch_id);
        });
    }

    /**
     * Fuel delivered. The caller posts finance.bill.received; this does not
     * post a second inventory event.
     */
    public function receive(Tank $tank, int $milli, int $unitCost, User $by, string $receiptId): void
    {
        $locked = $this->lock($tank);
        $this->guardCapacity($locked, $locked->quantity_milli + $milli);
        $this->add($locked, $milli, $unitCost, StockMovement::RECEIPT, FuelReceipt::class, $receiptId, 'receipt:'.$receiptId, null, $by);
    }

    /**
     * Take litres for a sale. Returns what those litres cost at the average.
     */
    public function issue(Tank $tank, int $milli, User $by, FuelSale $sale): int
    {
        $locked = $this->lock($tank);

        if ($milli <= 0 || $locked->quantity_milli < $milli) {
            throw ValidationException::withMessages(['litres' => 'The tank does not hold that much. '.Volume::toLitres($locked->quantity_milli).' L is on hand.']);
        }

        $cost = Volume::amount($milli, $locked->average_cost_minor);
        $locked->quantity_milli -= $milli;
        $locked->save();

        StockMovement::query()->create([
            'branch_id' => $locked->branch_id,
            'tank_id' => $locked->id,
            'kind' => StockMovement::SALE,
            'quantity_milli' => -$milli,
            'unit_cost_minor' => $locked->average_cost_minor,
            'reference_type' => FuelSale::class,
            'reference_id' => $sale->id,
            'idempotency_key' => 'sale:'.$sale->id,
            'created_by' => $by->id,
            'occurred_at' => now(),
        ]);

        return $cost;
    }

    /**
     * Litres leaving for another station. Returns the average cost per litre.
     * The same transfer cannot leave twice.
     */
    public function transferOut(Tank $tank, int $milli, User $by, string $transferId): int
    {
        $locked = $this->lock($tank);
        if ($milli <= 0 || $locked->quantity_milli < $milli) {
            throw ValidationException::withMessages(['litres' => 'The source tank does not hold that much. '.Volume::toLitres($locked->quantity_milli).' L is on hand.']);
        }

        $unit = $locked->average_cost_minor;
        $locked->quantity_milli -= $milli;
        $locked->save();

        StockMovement::query()->create([
            'branch_id' => $locked->branch_id,
            'tank_id' => $locked->id,
            'kind' => StockMovement::TRANSFER_OUT,
            'quantity_milli' => -$milli,
            'unit_cost_minor' => $unit,
            'reference_type' => 'fuel_transfer',
            'reference_id' => $transferId,
            'idempotency_key' => 'fuel-transfer:'.$transferId.':out',
            'created_by' => $by->id,
            'occurred_at' => now(),
        ]);

        return $unit;
    }

    /**
     * Litres arriving. Does not run before the source has been dispatched.
     */
    public function transferIn(Tank $tank, int $milli, int $unitCost, User $by, string $transferId): void
    {
        $locked = $this->lock($tank);
        $this->guardCapacity($locked, $locked->quantity_milli + $milli);
        $this->add($locked, $milli, $unitCost, StockMovement::TRANSFER_IN, 'fuel_transfer', $transferId, 'fuel-transfer:'.$transferId.':in', null, $by);
    }

    public function restore(Tank $tank, int $milli, int $costMinor, User $by, FuelSale $sale): void
    {
        $locked = $this->lock($tank);
        $newQty = $locked->quantity_milli + $milli;

        if ($newQty > 0) {
            $locked->average_cost_minor = (int) intdiv($locked->quantity_milli * $locked->average_cost_minor + $costMinor * 1000, $newQty);
        }

        $locked->quantity_milli = $newQty;
        $locked->save();

        StockMovement::query()->create([
            'branch_id' => $locked->branch_id,
            'tank_id' => $locked->id,
            'kind' => StockMovement::VOID,
            'quantity_milli' => $milli,
            'unit_cost_minor' => $milli > 0 ? (int) intdiv($costMinor * 1000, $milli) : 0,
            'reference_type' => FuelSale::class,
            'reference_id' => $sale->id,
            'idempotency_key' => 'void:'.$sale->id,
            'created_by' => $by->id,
            'occurred_at' => now(),
        ]);
    }

    public function measure(Tank $tank, int $measured, User $by, ?string $shiftId, ?string $notes): TankMeasurement
    {
        return app(Measurements::class)->record($tank, [
            'method' => TankMeasurement::VOLUME,
            'litres' => Volume::toLitres($measured),
            'depth_mm' => null,
            'water_mm' => null,
            'temperature_c' => null,
            'notes' => $notes,
            'evidence' => null,
        ], $by, $shiftId);
    }

    private function lock(Tank $tank): Tank
    {
        return Tank::query()->whereKey($tank->id)->lockForUpdate()->firstOrFail();
    }

    private function guardCapacity(Tank $tank, int $milli): void
    {
        if ($milli < 0 || $milli > $tank->capacity_milli) {
            throw ValidationException::withMessages(['litres' => 'Quantity must be within the tank capacity ('.Volume::toLitres($tank->capacity_milli).' L).']);
        }
    }

    private function add(Tank $locked, int $milli, int $unitCost, string $kind, string $referenceType, string $referenceId, string $key, ?string $notes, User $by): void
    {
        if ($milli <= 0) {
            throw ValidationException::withMessages(['litres' => 'Quantity must be greater than zero.']);
        }

        $newQty = $locked->quantity_milli + $milli;
        $locked->average_cost_minor = (int) intdiv($locked->quantity_milli * $locked->average_cost_minor + $milli * $unitCost, $newQty);
        $locked->quantity_milli = $newQty;
        $locked->save();

        StockMovement::query()->create([
            'branch_id' => $locked->branch_id,
            'tank_id' => $locked->id,
            'kind' => $kind,
            'quantity_milli' => $milli,
            'unit_cost_minor' => $unitCost,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'idempotency_key' => $key,
            'notes' => $notes,
            'created_by' => $by->id,
            'occurred_at' => now(),
        ]);
    }
}
