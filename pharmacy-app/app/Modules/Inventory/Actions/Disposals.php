<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Inventory\Enums\DisposalReason;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\StockDisposal;
use App\Support\DocumentNumbers;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Destroying stock takes two people: one asks (with the reason), another
 * approves, and only then does the stock leave the batch. Turned down, it
 * stays.
 */
class Disposals
{
    public function __construct(
        private readonly Stock $stock,
        private readonly DocumentNumbers $numbers,
    ) {}

    /**
     * @throws ValidationException
     */
    public function request(Batch $batch, int $quantity, DisposalReason $reason, ?string $note, User $by): StockDisposal
    {
        return DB::transaction(function () use ($batch, $quantity, $reason, $note, $by) {
            $locked = Batch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();

            // What other requests already ask for comes off what can be asked.
            $asked = (int) StockDisposal::query()
                ->where('batch_id', $locked->id)
                ->where('status', StockDisposal::PENDING)
                ->sum('quantity');
            $left = $locked->quantity_on_hand - $asked;

            if ($quantity < 1 || $quantity > $left) {
                throw ValidationException::withMessages([
                    'quantity' => $asked > 0
                        ? "Only {$left} of batch {$locked->batch_number} are not already awaiting disposal."
                        : "Only {$locked->quantity_on_hand} in batch {$locked->batch_number}.",
                ]);
            }

            return StockDisposal::query()->create([
                'branch_id' => $locked->branch_id,
                'number' => $this->numbers->next('stock_disposal', 'D', 5),
                'item_id' => $locked->item_id,
                'batch_id' => $locked->id,
                'quantity' => $quantity,
                'reason' => $reason,
                'note' => $note,
                'status' => StockDisposal::PENDING,
                'requested_by' => $by->id,
                'requested_at' => now(),
            ]);
        });
    }

    /**
     * @throws ValidationException
     */
    public function approve(StockDisposal $disposal, User $by, ?string $note): StockDisposal
    {
        return DB::transaction(function () use ($disposal, $by, $note) {
            $locked = $this->pending($disposal, $by);

            $this->stock->dispose($locked->batch()->firstOrFail(), $locked->quantity, $locked->reason->value, $locked->note, $by, $locked->id);

            return $this->decide($locked, StockDisposal::APPROVED, $by, $note);
        });
    }

    /**
     * @throws ValidationException
     */
    public function reject(StockDisposal $disposal, User $by, string $note): StockDisposal
    {
        return DB::transaction(fn () => $this->decide($this->pending($disposal, $by), StockDisposal::REJECTED, $by, $note));
    }

    /**
     * The request, locked, if it is still waiting and not the decider's own.
     *
     * @throws ValidationException
     */
    private function pending(StockDisposal $disposal, User $by): StockDisposal
    {
        $locked = StockDisposal::query()->whereKey($disposal->id)->lockForUpdate()->firstOrFail();

        if ($locked->status !== StockDisposal::PENDING) {
            throw ValidationException::withMessages(['disposal' => "Disposal {$locked->number} is already {$locked->status}."]);
        }
        if ($locked->requested_by === $by->id) {
            throw ValidationException::withMessages(['disposal' => 'Someone else has to decide on a disposal you asked for.']);
        }

        return $locked;
    }

    private function decide(StockDisposal $disposal, string $status, User $by, ?string $note): StockDisposal
    {
        $disposal->forceFill([
            'status' => $status,
            'decided_by' => $by->id,
            'decided_at' => now(),
            'decision_note' => $note,
        ])->save();

        return $disposal;
    }
}
