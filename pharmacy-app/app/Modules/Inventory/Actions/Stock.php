<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Inventory\Enums\AdjustmentReason;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Inventory\Models\StockMovement;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Miles\PlatformClient\Events\Outbox;

/**
 * The only way stock changes. Every change locks the batch rows it touches,
 * writes a StockMovement and updates the batch in one transaction, and
 * never lets a batch go below zero. Expired batches are never sold. Stock
 * leaving a batch comes off its locations too (Locations::fit).
 *
 * Changes outside buying and selling (opening stock, counts, disposals) go
 * to Finance at the batch's cost (finance.stock.adjusted), so inventory
 * there follows the shelves.
 */
class Stock
{
    public function __construct(
        private readonly Locations $locations,
        private readonly Outbox $outbox,
    ) {}

    /**
     * Stock arriving in a branch (opening balance or goods received). Adds to
     * the batch with the same number, or creates it; put straight at
     * $location when one is given, otherwise not put away.
     *
     * @throws ValidationException when the batch exists with another expiry date, or
     *                             the date is missing for an item that tracks expiry
     *                             (or given for one that does not)
     */
    public function add(
        Item $item,
        string $branchId,
        string $batchNumber,
        ?CarbonImmutable $expiresOn,
        int $quantity,
        int $unitCostMinor,
        User $user,
        MovementType $type = MovementType::Receipt,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?StockLocation $location = null,
    ): Batch {
        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => 'The quantity must be at least 1.']);
        }
        if ($item->tracks_expiry && $expiresOn === null) {
            throw ValidationException::withMessages(['expires_on' => "{$item->name} needs an expiry date."]);
        }
        if (! $item->tracks_expiry && $expiresOn !== null) {
            throw ValidationException::withMessages(['expires_on' => "{$item->name} does not track expiry dates."]);
        }

        return DB::transaction(function () use ($item, $branchId, $batchNumber, $expiresOn, $quantity, $unitCostMinor, $user, $type, $referenceType, $referenceId, $location) {
            $batch = Batch::query()
                ->where('branch_id', $branchId)
                ->where('item_id', $item->id)
                ->where('batch_number', $batchNumber)
                ->lockForUpdate()
                ->first();

            if ($batch === null) {
                $batch = new Batch([
                    'branch_id' => $branchId,
                    'item_id' => $item->id,
                    'batch_number' => $batchNumber,
                    'expires_on' => $expiresOn,
                    'unit_cost_minor' => $unitCostMinor,
                ]);
                $batch->quantity_on_hand = 0;
            } elseif ($batch->expires_on?->toDateString() !== $expiresOn?->toDateString()) {
                throw ValidationException::withMessages([
                    'expires_on' => "Batch {$batchNumber} is already in stock with expiry ".($batch->expires_on?->toDateString() ?? 'none').'.',
                ]);
            }

            $batch->quantity_on_hand += $quantity;
            $batch->save();

            $movement = $this->record($batch, $quantity, $type, $user, null, null, $referenceType, $referenceId);
            if ($type === MovementType::Opening) {
                $this->toFinance($batch, $quantity, 'opening', $movement->id, 'Stock on the shelf before the system');
            }
            if ($location !== null) {
                $this->locations->place($batch, $location, $quantity);
            }

            return $batch;
        });
    }

    /**
     * A counted, damaged, expired or lost quantity, by hand. Negative takes
     * stock away and can never go below zero.
     *
     * @throws ValidationException
     */
    public function adjust(Batch $batch, int $change, AdjustmentReason $reason, ?string $note, User $user): Batch
    {
        if ($change === 0) {
            throw ValidationException::withMessages(['change' => 'The change cannot be zero.']);
        }

        return DB::transaction(function () use ($batch, $change, $reason, $note, $user) {
            $locked = Batch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();

            if ($locked->quantity_on_hand + $change < 0) {
                throw ValidationException::withMessages([
                    'change' => "Only {$locked->quantity_on_hand} in batch {$locked->batch_number}.",
                ]);
            }

            $locked->quantity_on_hand += $change;
            $locked->save();

            $movement = $this->record($locked, $change, MovementType::Adjustment, $user, $reason->value, $note);
            $this->toFinance($locked, $change, 'count', $movement->id, $reason->label().($note === null ? '' : ": {$note}"));

            return $locked;
        });
    }

    /**
     * Sends a quantity of one batch back to its supplier (expired stock
     * too: that is often why). Never below zero.
     *
     * @throws ValidationException
     */
    public function returnToSupplier(Batch $batch, int $quantity, User $user, string $returnId, string $field): Batch
    {
        return DB::transaction(function () use ($batch, $quantity, $user, $returnId, $field) {
            $locked = Batch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();

            if ($quantity > $locked->quantity_on_hand) {
                throw ValidationException::withMessages([
                    $field => "Only {$locked->quantity_on_hand} left in batch {$locked->batch_number}.",
                ]);
            }

            $locked->quantity_on_hand -= $quantity;
            $locked->save();

            $this->record($locked, -$quantity, MovementType::SupplierReturn, $user, null, null, 'supplier_return', $returnId);

            return $locked;
        });
    }

    /**
     * Sends a quantity of one batch to another branch. Never below zero.
     *
     * @throws ValidationException
     */
    public function transferOut(Batch $batch, int $quantity, User $user, string $transferId, string $field): Batch
    {
        return DB::transaction(function () use ($batch, $quantity, $user, $transferId, $field) {
            $locked = Batch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();

            if ($quantity > $locked->quantity_on_hand) {
                throw ValidationException::withMessages([
                    $field => "Only {$locked->quantity_on_hand} left in batch {$locked->batch_number}.",
                ]);
            }

            $locked->quantity_on_hand -= $quantity;
            $locked->save();

            $this->record($locked, -$quantity, MovementType::TransferOut, $user, null, null, 'stock_transfer', $transferId);

            return $locked;
        });
    }

    /**
     * Destroys an approved disposal's quantity. Never below zero.
     *
     * @throws ValidationException
     */
    public function dispose(Batch $batch, int $quantity, string $reason, ?string $note, User $user, string $disposalId, string $disposalNumber): Batch
    {
        return DB::transaction(function () use ($batch, $quantity, $reason, $note, $user, $disposalId, $disposalNumber) {
            $locked = Batch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();

            if ($quantity > $locked->quantity_on_hand) {
                throw ValidationException::withMessages([
                    'disposal' => "Only {$locked->quantity_on_hand} left in batch {$locked->batch_number}.",
                ]);
            }

            $locked->quantity_on_hand -= $quantity;
            $locked->save();

            $this->record($locked, -$quantity, MovementType::Disposal, $user, $reason, $note, 'stock_disposal', $disposalId);
            $this->toFinance($locked, -$quantity, 'write_off', $disposalNumber, ucfirst($reason).($note === null ? '' : ": {$note}"));

            return $locked;
        });
    }

    /**
     * Takes a quantity of an item from a branch for a sale: soonest expiry
     * first (FEFO; undated stock last, by batch), never from expired batches
     * or batches on hold. All or nothing.
     *
     * @return list<array{batch: Batch, quantity: int}>
     *
     * @throws InsufficientStock
     */
    public function take(Item $item, string $branchId, int $quantity, User $user, string $referenceType, string $referenceId, ?CarbonImmutable $today = null): array
    {
        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => 'The quantity must be at least 1.']);
        }

        $today = ($today ?? CarbonImmutable::today())->startOfDay();

        return DB::transaction(function () use ($item, $branchId, $quantity, $user, $referenceType, $referenceId, $today) {
            $batches = Batch::query()
                ->where('branch_id', $branchId)
                ->where('item_id', $item->id)
                ->where('quantity_on_hand', '>', 0)
                ->where('status', Batch::AVAILABLE)
                ->unexpired($today)
                ->firstToExpire()
                ->orderBy('batch_number')
                ->lockForUpdate()
                ->get();

            $available = (int) $batches->sum('quantity_on_hand');
            if ($available < $quantity) {
                throw new InsufficientStock($item, $quantity, $available);
            }

            $taken = [];
            $remaining = $quantity;

            foreach ($batches as $batch) {
                if ($remaining === 0) {
                    break;
                }

                $part = min($remaining, $batch->quantity_on_hand);
                $batch->quantity_on_hand -= $part;
                $batch->save();

                $this->record($batch, -$part, MovementType::Sale, $user, null, null, $referenceType, $referenceId);

                $taken[] = ['batch' => $batch, 'quantity' => $part];
                $remaining -= $part;
            }

            return $taken;
        });
    }

    /**
     * Puts stock back into the batch it came from (a voided sale, a return).
     */
    public function putBack(Batch $batch, int $quantity, User $user, string $referenceType, string $referenceId, MovementType $type = MovementType::SaleVoid): void
    {
        DB::transaction(function () use ($batch, $quantity, $user, $referenceType, $referenceId, $type) {
            $locked = Batch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $locked->quantity_on_hand += $quantity;
            $locked->save();

            $this->record($locked, $quantity, $type, $user, null, null, $referenceType, $referenceId);
        });
    }

    /**
     * Quantity of an item a branch can sell today.
     */
    public function sellable(Item $item, string $branchId, ?CarbonImmutable $today = null): int
    {
        return (int) Batch::query()
            ->where('branch_id', $branchId)
            ->where('item_id', $item->id)
            ->where('status', Batch::AVAILABLE)
            ->unexpired(($today ?? CarbonImmutable::today())->startOfDay())
            ->sum('quantity_on_hand');
    }

    private function record(Batch $batch, int $quantity, MovementType $type, User $user, ?string $reason, ?string $note, ?string $referenceType = null, ?string $referenceId = null): StockMovement
    {
        $movement = StockMovement::query()->create([
            'branch_id' => $batch->branch_id,
            'item_id' => $batch->item_id,
            'batch_id' => $batch->id,
            'quantity' => $quantity,
            'type' => $type,
            'reason' => $reason,
            'note' => $note,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'user_id' => $user->id,
        ]);

        if ($quantity < 0) {
            $this->locations->fit($batch);
        }

        return $movement;
    }

    /**
     * A change at the batch's cost, signed: positive stock gained.
     *
     * @param  'opening'|'count'|'write_off'  $kind
     */
    private function toFinance(Batch $batch, int $quantity, string $kind, string $reference, string $reason): void
    {
        $amount = $quantity * $batch->unit_cost_minor;
        if ($amount === 0) {
            return;
        }

        $batch->loadMissing('item');
        $this->outbox->record('finance', 'finance.stock.adjusted', 1, "stock_adjustment:{$kind}:{$reference}", [
            'reference' => $reference,
            'branch_id' => $batch->branch_id,
            'currency' => Money::currency(),
            'adjusted_on' => now()->toDateString(),
            'kind' => $kind,
            'amount_minor' => $amount,
            'reason' => mb_substr($reason, 0, 255),
            'lines' => [[
                'description' => "{$batch->item->name}, batch {$batch->batch_number}",
                'quantity' => $quantity,
                'amount_minor' => $amount,
            ]],
        ], now());
    }
}
