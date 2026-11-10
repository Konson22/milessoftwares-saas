<?php

namespace App\Petrol;

use App\Models\FuelTransfer;
use App\Models\Tank;
use App\Models\User;
use App\Support\DocumentNumbers;
use App\Support\Volume;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fuel transfers inside one company. Stock moves on dispatch and on receipt,
 * each exactly once. Litres still on the road are the dispatched quantity
 * that has not been received.
 */
class FuelMoves
{
    public function __construct(
        private readonly TankLedger $tanks,
        private readonly Audit $audit,
        private readonly DocumentNumbers $numbers,
    ) {}

    public function request(Tank $source, Tank $destination, int $milli, ?string $transport, User $by): FuelTransfer
    {
        $this->pair($source, $destination);
        if ($milli < 1) {
            throw ValidationException::withMessages(['litres' => 'Enter a quantity.']);
        }

        return DB::transaction(function () use ($source, $destination, $milli, $transport, $by) {
            $transfer = FuelTransfer::query()->create([
                'branch_id' => $source->branch_id,
                'destination_branch_id' => $destination->branch_id,
                'number' => $this->numbers->next('fuel_transfer', 'FT'),
                'source_tank_id' => $source->id,
                'destination_tank_id' => $destination->id,
                'fuel_product_id' => $source->fuel_product_id,
                'requested_milli' => $milli,
                'dispatched_milli' => 0,
                'received_milli' => 0,
                'discrepancy_milli' => 0,
                'status' => FuelTransfer::REQUESTED,
                'notes' => $transport,
                'created_by' => $by->id,
                'transferred_at' => now(),
            ]);
            $this->audit->record($by, 'fuel_transfer.requested', $transfer, null, ['milli' => $milli], $source->branch_id);

            return $transfer;
        });
    }

    public function approve(FuelTransfer $transfer, User $by): FuelTransfer
    {
        return $this->step($transfer, FuelTransfer::REQUESTED, FuelTransfer::APPROVED, $by, 'fuel_transfer.approved', [
            'approved_by' => $by->id,
            'approved_at' => now(),
        ]);
    }

    public function dispatch(FuelTransfer $transfer, int $milli, User $by): FuelTransfer
    {
        if ($milli < 1) {
            throw ValidationException::withMessages(['litres' => 'Enter the quantity that left.']);
        }

        return DB::transaction(function () use ($transfer, $milli, $by) {
            $locked = $this->lock($transfer, FuelTransfer::APPROVED);
            $tank = Tank::query()->whereKey($locked->source_tank_id)->firstOrFail();
            $unit = $this->tanks->transferOut($tank, $milli, $by, $locked->id);
            $locked->fill([
                'status' => FuelTransfer::DISPATCHED,
                'dispatched_milli' => $milli,
                'unit_cost_minor' => $unit,
                'dispatched_by' => $by->id,
                'dispatched_at' => now(),
            ])->save();
            $this->audit->record($by, 'fuel_transfer.dispatched', $locked, null, ['milli' => $milli], $locked->branch_id);

            return $locked;
        });
    }

    public function receive(FuelTransfer $transfer, int $milli, User $by): FuelTransfer
    {
        if ($milli < 0) {
            throw ValidationException::withMessages(['litres' => 'Received quantity cannot be negative.']);
        }

        return DB::transaction(function () use ($transfer, $milli, $by) {
            $locked = $this->lock($transfer, FuelTransfer::DISPATCHED);
            $tank = Tank::query()->whereKey($locked->destination_tank_id)->firstOrFail();
            if ($tank->fuel_product_id !== $locked->fuel_product_id) {
                throw ValidationException::withMessages(['tank_id' => 'The destination tank holds a different fuel.']);
            }
            if ($milli > 0) {
                $this->tanks->transferIn($tank, $milli, (int) $locked->unit_cost_minor, $by, $locked->id);
            }
            $locked->fill([
                'status' => FuelTransfer::RECEIVED,
                'received_milli' => $milli,
                'discrepancy_milli' => $milli - (int) $locked->dispatched_milli,
                'received_by' => $by->id,
                'received_at' => now(),
            ])->save();
            $this->audit->record($by, 'fuel_transfer.received', $locked, null, [
                'milli' => $milli,
                'discrepancy_milli' => $locked->discrepancy_milli,
            ], $locked->destination_branch_id);

            return $locked;
        });
    }

    public function complete(FuelTransfer $transfer, User $by): FuelTransfer
    {
        return $this->step($transfer, FuelTransfer::RECEIVED, FuelTransfer::COMPLETED, $by, 'fuel_transfer.completed', [
            'completed_at' => now(),
        ]);
    }

    private function pair(Tank $source, Tank $destination): void
    {
        if ($source->branch_id === $destination->branch_id) {
            throw ValidationException::withMessages(['destination_tank_id' => 'Choose a tank at another station.']);
        }
        if ($source->fuel_product_id !== $destination->fuel_product_id || $source->status !== Tank::ACTIVE || $destination->status !== Tank::ACTIVE) {
            throw ValidationException::withMessages(['destination_tank_id' => 'Both tanks must be active and hold the same fuel.']);
        }
        if ($source->quantity_milli < 1) {
            throw ValidationException::withMessages(['litres' => 'The source tank is empty. '.Volume::toLitres($source->quantity_milli).' L is on hand.']);
        }
    }

    /**
     * @param  array<string, mixed>  $fill
     */
    private function step(FuelTransfer $transfer, string $from, string $to, User $by, string $action, array $fill): FuelTransfer
    {
        return DB::transaction(function () use ($transfer, $from, $to, $by, $action, $fill) {
            $locked = $this->lock($transfer, $from);
            $locked->fill($fill + ['status' => $to])->save();
            $this->audit->record($by, $action, $locked, null, ['status' => $to], $locked->branch_id);

            return $locked;
        });
    }

    private function lock(FuelTransfer $transfer, string $expected): FuelTransfer
    {
        $locked = FuelTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== $expected) {
            throw ValidationException::withMessages(['transfer' => 'This transfer is '.$locked->status.'.']);
        }

        return $locked;
    }
}
