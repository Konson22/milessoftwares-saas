<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\StockDisposal;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Inventory\Models\StockTransfer;
use App\Support\DocumentNumbers;
use App\Support\Industry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stock sent between branches. Sending takes it out of the sender's
 * batches at once; it is in neither branch while on the road, and arrives
 * in a batch of the same number, expiry and cost when the receiving branch
 * takes it in. Until then the sender can cancel and have it back.
 *
 * Nothing goes to Finance: the stock stays the organization's, at cost.
 */
class Transfers
{
    public function __construct(
        private readonly Stock $stock,
        private readonly DocumentNumbers $numbers,
        private readonly Industry $industry,
    ) {}

    /**
     * @param  list<array{batch_id: string, quantity: int}>  $lines
     *
     * @throws ValidationException
     */
    public function send(string $fromBranchId, string $toBranchId, array $lines, ?string $note, User $by): StockTransfer
    {
        if ($fromBranchId === $toBranchId) {
            throw ValidationException::withMessages(['to_branch_id' => 'Choose another branch to send to.']);
        }

        return DB::transaction(function () use ($fromBranchId, $toBranchId, $lines, $note, $by) {
            $transfer = StockTransfer::query()->create([
                'number' => $this->numbers->next('stock_transfer', 'T', 5),
                'from_branch_id' => $fromBranchId,
                'application' => $this->industry->code(),
                'to_branch_id' => $toBranchId,
                'status' => StockTransfer::IN_TRANSIT,
                'note' => $note,
                'sent_by' => $by->id,
                'sent_at' => now(),
            ]);

            $seen = [];
            foreach ($lines as $index => $line) {
                $field = "lines.{$index}.quantity";
                if (isset($seen[$line['batch_id']])) {
                    throw ValidationException::withMessages(["lines.{$index}.batch_id" => 'That batch is already on the transfer.']);
                }
                $seen[$line['batch_id']] = true;

                $batch = Batch::query()->where('branch_id', $fromBranchId)->whereKey($line['batch_id'])->lockForUpdate()->first()
                    ?? throw ValidationException::withMessages(["lines.{$index}.batch_id" => 'Not a batch of this branch.']);
                if (! $this->industry->handles($batch->item->kind)) {
                    throw ValidationException::withMessages(["lines.{$index}.batch_id" => 'This item is not kept here.']);
                }
                if ($batch->onHold()) {
                    throw ValidationException::withMessages(["lines.{$index}.batch_id" => "Batch {$batch->batch_number} is on hold."]);
                }
                if ($batch->isExpired()) {
                    throw ValidationException::withMessages(["lines.{$index}.batch_id" => "Batch {$batch->batch_number} has expired."]);
                }

                // What is waiting to be destroyed cannot be sent.
                $awaiting = (int) StockDisposal::query()->where('batch_id', $batch->id)->where('status', StockDisposal::PENDING)->sum('quantity');
                $left = $batch->quantity_on_hand - $awaiting;
                if ($line['quantity'] < 1 || $line['quantity'] > $left) {
                    throw ValidationException::withMessages([$field => "Only {$left} of batch {$batch->batch_number} can be sent."]);
                }

                $this->stock->transferOut($batch, $line['quantity'], $by, $transfer->id, $field);

                $transfer->lines()->create([
                    'item_id' => $batch->item_id,
                    'batch_id' => $batch->id,
                    'batch_number' => $batch->batch_number,
                    'expires_on' => $batch->expires_on,
                    'unit_cost_minor' => $batch->unit_cost_minor,
                    'quantity' => $line['quantity'],
                ]);
            }

            if ($seen === []) {
                throw ValidationException::withMessages(['lines' => 'Add at least one batch to send.']);
            }

            return $transfer;
        });
    }

    /**
     * Takes the stock into the receiving branch, onto a location of it if
     * one is named.
     *
     * @throws ValidationException
     */
    public function receive(StockTransfer $transfer, User $by, ?StockLocation $location = null): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $by, $location) {
            $locked = $this->inTransit($transfer);
            if ($location !== null && ($location->branch_id !== $locked->to_branch_id || ! $location->active)) {
                throw ValidationException::withMessages(['location_id' => 'Not a location in use in this branch.']);
            }

            foreach ($locked->lines()->with('item')->get() as $line) {
                $batch = $this->stock->add(
                    item: $line->item,
                    branchId: $locked->to_branch_id,
                    batchNumber: $line->batch_number,
                    expiresOn: $line->expires_on,
                    quantity: $line->quantity,
                    unitCostMinor: $line->unit_cost_minor,
                    user: $by,
                    type: MovementType::TransferIn,
                    referenceType: 'stock_transfer',
                    referenceId: $locked->id,
                    location: $location,
                );
                $line->forceFill(['received_batch_id' => $batch->id])->save();
            }

            $locked->forceFill(['status' => StockTransfer::RECEIVED, 'received_by' => $by->id, 'received_at' => now()])->save();

            return $locked;
        });
    }

    /**
     * Calls the stock back into the batches it left.
     *
     * @throws ValidationException
     */
    public function cancel(StockTransfer $transfer, User $by): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $by) {
            $locked = $this->inTransit($transfer);

            foreach ($locked->lines()->with('batch')->get() as $line) {
                $this->stock->putBack($line->batch, $line->quantity, $by, 'stock_transfer', $locked->id, MovementType::TransferIn);
            }

            $locked->forceFill(['status' => StockTransfer::CANCELLED, 'cancelled_by' => $by->id, 'cancelled_at' => now()])->save();

            return $locked;
        });
    }

    /**
     * @throws ValidationException
     */
    private function inTransit(StockTransfer $transfer): StockTransfer
    {
        $locked = StockTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== StockTransfer::IN_TRANSIT) {
            throw ValidationException::withMessages(['transfer' => "Transfer {$locked->number} is already ".str_replace('_', ' ', $locked->status).'.']);
        }

        return $locked;
    }
}
