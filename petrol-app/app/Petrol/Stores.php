<?php

namespace App\Petrol;

use App\Models\Store;
use App\Models\StoreBalance;
use App\Models\StoreMovement;
use App\Models\StoreProduct;
use App\Models\StoreTransfer;
use App\Models\User;
use App\Support\DocumentNumbers;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Non-fuel stock. Balances change only through a movement, and a movement
 * key cannot post twice.
 */
class Stores
{
    public function __construct(
        private readonly Audit $audit,
        private readonly DocumentNumbers $numbers,
    ) {}

    public function receive(Store $store, StoreProduct $product, int $milli, int $unitCost, User $by, string $key): StoreBalance
    {
        return $this->post($store, $product, $milli, $unitCost, StoreMovement::RECEIVE, $key, $by);
    }

    public function issue(Store $store, StoreProduct $product, int $milli, User $by, string $key, ?string $referenceType = null, ?string $referenceId = null): StoreBalance
    {
        return DB::transaction(function () use ($store, $product, $milli, $by, $key, $referenceType, $referenceId) {
            if ($this->alreadyPosted($key)) {
                return $this->lockBalance($store, $product);
            }
            $balance = $this->lockBalance($store, $product);
            if ($milli < 1 || $balance->quantity_milli < $milli) {
                throw ValidationException::withMessages(['quantity' => 'The store does not hold that much.']);
            }
            $this->movement($store, $product, -$milli, $balance->average_cost_minor, StoreMovement::ISSUE, $key, $by, $referenceType, $referenceId);
            $balance->quantity_milli -= $milli;
            $balance->save();

            return $balance;
        });
    }

    public function adjust(Store $store, StoreProduct $product, int $deltaMilli, User $by, string $key): StoreBalance
    {
        return DB::transaction(function () use ($store, $product, $deltaMilli, $by, $key) {
            if ($this->alreadyPosted($key)) {
                return $this->lockBalance($store, $product);
            }
            $balance = $this->lockBalance($store, $product);
            $next = $balance->quantity_milli + $deltaMilli;
            if ($next < 0) {
                throw ValidationException::withMessages(['quantity' => 'That adjustment would take stock below zero.']);
            }
            $this->movement($store, $product, $deltaMilli, $balance->average_cost_minor, StoreMovement::ADJUST, $key, $by, null, null);
            $balance->quantity_milli = $next;
            $balance->save();
            $this->audit->record($by, 'store.adjusted', $balance, null, ['delta_milli' => $deltaMilli], $store->branch_id);

            return $balance;
        });
    }

    public function count(Store $store, StoreProduct $product, int $countedMilli, User $by, string $key): StoreBalance
    {
        if ($countedMilli < 0) {
            throw ValidationException::withMessages(['quantity' => 'A count cannot be negative.']);
        }

        return DB::transaction(function () use ($store, $product, $countedMilli, $by, $key) {
            if ($this->alreadyPosted($key)) {
                return $this->lockBalance($store, $product);
            }
            $balance = $this->lockBalance($store, $product);
            $delta = $countedMilli - $balance->quantity_milli;
            $this->movement($store, $product, $delta, $balance->average_cost_minor, StoreMovement::COUNT, $key, $by, null, null);
            $balance->quantity_milli = $countedMilli;
            $balance->save();
            $this->audit->record($by, 'store.counted', $balance, null, ['counted_milli' => $countedMilli], $store->branch_id);

            return $balance;
        });
    }

    public function requestTransfer(Store $source, Store $destination, StoreProduct $product, int $milli, User $by): StoreTransfer
    {
        if ($source->id === $destination->id || ! $source->active || ! $destination->active) {
            throw ValidationException::withMessages(['destination_store_id' => 'Choose another open store.']);
        }
        if ($milli < 1) {
            throw ValidationException::withMessages(['quantity' => 'Enter a quantity.']);
        }

        return DB::transaction(function () use ($source, $destination, $product, $milli, $by) {
            $transfer = StoreTransfer::query()->create([
                'number' => $this->numbers->next('store_transfer', 'ST'),
                'source_store_id' => $source->id,
                'destination_store_id' => $destination->id,
                'product_id' => $product->id,
                'requested_milli' => $milli,
                'status' => StoreTransfer::REQUESTED,
                'requested_by' => $by->id,
            ]);
            $this->audit->record($by, 'store_transfer.requested', $transfer, null, ['milli' => $milli], $source->branch_id);

            return $transfer;
        });
    }

    public function approveTransfer(StoreTransfer $transfer, User $by): StoreTransfer
    {
        return $this->step($transfer, StoreTransfer::REQUESTED, StoreTransfer::APPROVED, $by, 'store_transfer.approved');
    }

    public function dispatchTransfer(StoreTransfer $transfer, int $milli, User $by): StoreTransfer
    {
        return DB::transaction(function () use ($transfer, $milli, $by) {
            $locked = $this->lockTransfer($transfer, StoreTransfer::APPROVED);
            $store = Store::query()->whereKey($locked->source_store_id)->firstOrFail();
            $product = StoreProduct::query()->whereKey($locked->product_id)->firstOrFail();
            $balance = $this->issue($store, $product, $milli, $by, 'store-transfer:'.$locked->id.':out', StoreTransfer::class, $locked->id);
            $locked->fill([
                'status' => StoreTransfer::DISPATCHED,
                'dispatched_milli' => $milli,
                'unit_cost_minor' => $balance->average_cost_minor,
                'dispatched_by' => $by->id,
                'dispatched_at' => now(),
            ])->save();

            return $locked;
        });
    }

    public function receiveTransfer(StoreTransfer $transfer, int $milli, User $by): StoreTransfer
    {
        return DB::transaction(function () use ($transfer, $milli, $by) {
            $locked = $this->lockTransfer($transfer, StoreTransfer::DISPATCHED);
            $store = Store::query()->whereKey($locked->destination_store_id)->firstOrFail();
            $product = StoreProduct::query()->whereKey($locked->product_id)->firstOrFail();
            if ($milli > 0) {
                $this->receive($store, $product, $milli, (int) $locked->unit_cost_minor, $by, 'store-transfer:'.$locked->id.':in');
            }
            $locked->fill([
                'status' => StoreTransfer::RECEIVED,
                'received_milli' => $milli,
                'variance_milli' => $milli - (int) $locked->dispatched_milli,
                'received_by' => $by->id,
                'received_at' => now(),
            ])->save();

            return $locked;
        });
    }

    public function completeTransfer(StoreTransfer $transfer, User $by): StoreTransfer
    {
        return $this->step($transfer, StoreTransfer::RECEIVED, StoreTransfer::COMPLETED, $by, 'store_transfer.completed');
    }

    private function post(Store $store, StoreProduct $product, int $milli, int $unitCost, string $kind, string $key, User $by): StoreBalance
    {
        if ($milli < 1 || $unitCost < 0) {
            throw ValidationException::withMessages(['quantity' => 'Enter a quantity and a cost.']);
        }

        return DB::transaction(function () use ($store, $product, $milli, $unitCost, $kind, $key, $by) {
            if ($this->alreadyPosted($key)) {
                return $this->lockBalance($store, $product);
            }
            $balance = $this->lockBalance($store, $product);
            $newQty = $balance->quantity_milli + $milli;
            $balance->average_cost_minor = (int) intdiv($balance->quantity_milli * $balance->average_cost_minor + $milli * $unitCost, $newQty);
            $balance->quantity_milli = $newQty;
            $balance->save();
            $this->movement($store, $product, $milli, $unitCost, $kind, $key, $by, null, null);

            return $balance;
        });
    }

    private function alreadyPosted(string $key): bool
    {
        return StoreMovement::query()->where('idempotency_key', $key)->exists();
    }

    private function lockBalance(Store $store, StoreProduct $product): StoreBalance
    {
        $balance = StoreBalance::query()->where('store_id', $store->id)->where('product_id', $product->id)->lockForUpdate()->first();
        if ($balance === null) {
            $balance = StoreBalance::query()->create([
                'store_id' => $store->id,
                'product_id' => $product->id,
                'quantity_milli' => 0,
                'average_cost_minor' => 0,
            ]);
        }

        return $balance;
    }

    private function movement(Store $store, StoreProduct $product, int $milli, int $unitCost, string $kind, string $key, User $by, ?string $referenceType, ?string $referenceId): void
    {
        StoreMovement::query()->create([
            'branch_id' => $store->branch_id,
            'store_id' => $store->id,
            'product_id' => $product->id,
            'kind' => $kind,
            'quantity_milli' => $milli,
            'unit_cost_minor' => $unitCost,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'idempotency_key' => $key,
            'created_by' => $by->id,
            'occurred_at' => now(),
        ]);
    }

    private function step(StoreTransfer $transfer, string $from, string $to, User $by, string $action): StoreTransfer
    {
        return DB::transaction(function () use ($transfer, $from, $to, $by, $action) {
            $locked = $this->lockTransfer($transfer, $from);
            $locked->fill(['status' => $to] + ($to === StoreTransfer::APPROVED
                ? ['approved_by' => $by->id, 'approved_at' => now()]
                : ['completed_at' => now()]))->save();
            $this->audit->record($by, $action, $locked, null, ['status' => $to], null);

            return $locked;
        });
    }

    private function lockTransfer(StoreTransfer $transfer, string $expected): StoreTransfer
    {
        $locked = StoreTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== $expected) {
            throw ValidationException::withMessages(['transfer' => 'This transfer is '.$locked->status.'.']);
        }

        return $locked;
    }
}
