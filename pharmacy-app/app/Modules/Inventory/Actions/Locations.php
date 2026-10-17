<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\BatchLocation;
use App\Modules\Inventory\Models\StockLocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Where a branch's stock is kept. Units of a batch are placed at locations
 * or are not put away; moving them never changes what is on hand. When
 * stock leaves a batch (sold, disposed of, sent back, counted short) it
 * comes off what is not put away first, then off the locations in name
 * order, so the places never hold more than the batch has.
 */
class Locations
{
    /**
     * @throws ValidationException
     */
    public function create(string $branchId, string $name): StockLocation
    {
        $name = trim($name);
        $this->ensureNameFree($branchId, $name);

        return StockLocation::query()->create(['branch_id' => $branchId, 'name' => $name]);
    }

    /**
     * @throws ValidationException
     */
    public function update(StockLocation $location, string $name, bool $active): StockLocation
    {
        $name = trim($name);
        $this->ensureNameFree($location->branch_id, $name, $location->id);

        if (! $active && BatchLocation::query()->where('stock_location_id', $location->id)->where('quantity', '>', 0)->exists()) {
            throw ValidationException::withMessages(['active' => "Move what is kept at {$location->name} elsewhere first."]);
        }

        $location->forceFill(['name' => $name, 'active' => $active])->save();

        return $location;
    }

    /**
     * Moves units of a batch between two of its branch's locations, or to
     * or from "not put away" (null).
     *
     * @throws ValidationException
     */
    public function move(Batch $batch, ?StockLocation $from, ?StockLocation $to, int $quantity, User $by): void
    {
        if ($from?->id === $to?->id) {
            throw ValidationException::withMessages(['to_location_id' => 'Choose somewhere else to move it to.']);
        }
        foreach ([$from, $to] as $location) {
            if ($location !== null && ($location->branch_id !== $batch->branch_id || ! $location->active)) {
                throw ValidationException::withMessages(['to_location_id' => 'Not a location of this branch.']);
            }
        }

        DB::transaction(function () use ($batch, $from, $to, $quantity, $by) {
            $locked = Batch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $available = $from === null ? $this->notPutAway($locked) : $this->placement($locked, $from)->quantity;

            if ($quantity < 1 || $quantity > $available) {
                throw ValidationException::withMessages(['quantity' => "Only {$available} of batch {$locked->batch_number} ".($from === null ? 'are not put away.' : "are at {$from->name}.")]);
            }

            if ($from !== null) {
                $this->adjustPlacement($this->placement($locked, $from), -$quantity);
            }
            if ($to !== null) {
                $this->adjustPlacement($this->placement($locked, $to), $quantity);
            }

            DB::table('stock_location_moves')->insert([
                'id' => (string) str()->uuid(),
                'organization_id' => $locked->organization_id,
                'branch_id' => $locked->branch_id,
                'batch_id' => $locked->id,
                'from_location_id' => $from?->id,
                'to_location_id' => $to?->id,
                'quantity' => $quantity,
                'user_id' => $by->id,
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Puts new units of a locked batch straight at a location.
     */
    public function place(Batch $locked, StockLocation $location, int $quantity): void
    {
        $this->adjustPlacement($this->placement($locked, $location), $quantity);
    }

    /**
     * After a locked batch lost stock: what the locations hold beyond what
     * is now on hand comes off them, in name order.
     */
    public function fit(Batch $locked): void
    {
        $placements = BatchLocation::query()
            ->where('batch_id', $locked->id)
            ->where('quantity', '>', 0)
            ->join('stock_locations', 'stock_locations.id', '=', 'batch_locations.stock_location_id')
            ->orderBy('stock_locations.name')
            ->select('batch_locations.*')
            ->lockForUpdate()
            ->get();

        $excess = (int) $placements->sum('quantity') - $locked->quantity_on_hand;
        foreach ($placements as $placement) {
            if ($excess <= 0) {
                break;
            }

            $part = min($excess, $placement->quantity);
            $this->adjustPlacement($placement, -$part);
            $excess -= $part;
        }
    }

    public function notPutAway(Batch $batch): int
    {
        return $batch->quantity_on_hand - (int) BatchLocation::query()->where('batch_id', $batch->id)->sum('quantity');
    }

    private function placement(Batch $batch, StockLocation $location): BatchLocation
    {
        return BatchLocation::query()
            ->where('batch_id', $batch->id)
            ->where('stock_location_id', $location->id)
            ->lockForUpdate()
            ->first()
            ?? new BatchLocation(['batch_id' => $batch->id, 'stock_location_id' => $location->id, 'quantity' => 0]);
    }

    private function adjustPlacement(BatchLocation $placement, int $change): void
    {
        $placement->quantity += $change;
        $placement->save();
    }

    /**
     * @throws ValidationException
     */
    private function ensureNameFree(string $branchId, string $name, ?string $ignoreId = null): void
    {
        $taken = StockLocation::query()
            ->where('branch_id', $branchId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($name === '' || $taken) {
            throw ValidationException::withMessages(['name' => $name === '' ? 'Give the location a name.' : "{$name} is already a location here."]);
        }
    }
}
