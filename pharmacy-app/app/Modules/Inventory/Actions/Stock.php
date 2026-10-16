<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Inventory\Enums\AdjustmentReason;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Pharmacy\Models\Medicine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only way stock changes. Every change locks the batch rows it touches,
 * writes a StockMovement and updates the batch in one transaction, and
 * never lets a batch go below zero. Expired batches are never sold.
 */
class Stock
{
    /**
     * Stock arriving in a branch (opening balance or goods received). Adds to
     * the batch with the same number, or creates it.
     *
     * @throws ValidationException when the batch exists with another expiry date
     */
    public function add(
        Medicine $medicine,
        string $branchId,
        string $batchNumber,
        CarbonImmutable $expiresOn,
        int $quantity,
        int $unitCostMinor,
        User $user,
        MovementType $type = MovementType::Receipt,
        ?string $referenceType = null,
        ?string $referenceId = null,
    ): Batch {
        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => 'The quantity must be at least 1.']);
        }

        return DB::transaction(function () use ($medicine, $branchId, $batchNumber, $expiresOn, $quantity, $unitCostMinor, $user, $type, $referenceType, $referenceId) {
            $batch = Batch::query()
                ->where('branch_id', $branchId)
                ->where('medicine_id', $medicine->id)
                ->where('batch_number', $batchNumber)
                ->lockForUpdate()
                ->first();

            if ($batch === null) {
                $batch = new Batch([
                    'branch_id' => $branchId,
                    'medicine_id' => $medicine->id,
                    'batch_number' => $batchNumber,
                    'expires_on' => $expiresOn,
                    'unit_cost_minor' => $unitCostMinor,
                ]);
                $batch->quantity_on_hand = 0;
            } elseif (! $batch->expires_on->isSameDay($expiresOn)) {
                throw ValidationException::withMessages([
                    'expires_on' => "Batch {$batchNumber} is already in stock with expiry {$batch->expires_on->toDateString()}.",
                ]);
            }

            $batch->quantity_on_hand += $quantity;
            $batch->save();

            $this->record($batch, $quantity, $type, $user, null, null, $referenceType, $referenceId);

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

            $this->record($locked, $change, MovementType::Adjustment, $user, $reason->value, $note);

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
     * Takes a quantity of a medicine from a branch for a sale: oldest expiry
     * first (FEFO), never from expired batches. All or nothing.
     *
     * @return list<array{batch: Batch, quantity: int}>
     *
     * @throws InsufficientStock
     */
    public function take(Medicine $medicine, string $branchId, int $quantity, User $user, string $referenceType, string $referenceId, ?CarbonImmutable $today = null): array
    {
        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => 'The quantity must be at least 1.']);
        }

        $today = ($today ?? CarbonImmutable::today())->startOfDay();

        return DB::transaction(function () use ($medicine, $branchId, $quantity, $user, $referenceType, $referenceId, $today) {
            $batches = Batch::query()
                ->where('branch_id', $branchId)
                ->where('medicine_id', $medicine->id)
                ->where('quantity_on_hand', '>', 0)
                ->whereDate('expires_on', '>', $today)
                ->orderBy('expires_on')
                ->orderBy('batch_number')
                ->lockForUpdate()
                ->get();

            $available = (int) $batches->sum('quantity_on_hand');
            if ($available < $quantity) {
                throw new InsufficientStock($medicine, $quantity, $available);
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
     * Puts stock back into the batch it came from (a voided sale).
     */
    public function putBack(Batch $batch, int $quantity, User $user, string $referenceType, string $referenceId): void
    {
        DB::transaction(function () use ($batch, $quantity, $user, $referenceType, $referenceId) {
            $locked = Batch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $locked->quantity_on_hand += $quantity;
            $locked->save();

            $this->record($locked, $quantity, MovementType::SaleVoid, $user, null, null, $referenceType, $referenceId);
        });
    }

    /**
     * Quantity of a medicine a branch can sell today.
     */
    public function sellable(Medicine $medicine, string $branchId, ?CarbonImmutable $today = null): int
    {
        return (int) Batch::query()
            ->where('branch_id', $branchId)
            ->where('medicine_id', $medicine->id)
            ->whereDate('expires_on', '>', ($today ?? CarbonImmutable::today())->startOfDay())
            ->sum('quantity_on_hand');
    }

    private function record(Batch $batch, int $quantity, MovementType $type, User $user, ?string $reason, ?string $note, ?string $referenceType = null, ?string $referenceId = null): void
    {
        StockMovement::query()->create([
            'branch_id' => $batch->branch_id,
            'medicine_id' => $batch->medicine_id,
            'batch_id' => $batch->id,
            'quantity' => $quantity,
            'type' => $type,
            'reason' => $reason,
            'note' => $note,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'user_id' => $user->id,
        ]);
    }
}
