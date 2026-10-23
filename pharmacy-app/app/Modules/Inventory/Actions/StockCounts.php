<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Inventory\Enums\AdjustmentReason;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\StockCount;
use App\Support\DocumentNumbers;
use App\Support\Industry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stock-takes. A sheet lists the branch's batches of this industry (or of
 * one category) holding stock; each line is counted, and records what was
 * on hand at that moment; completing the count posts every difference as a
 * count correction (Stock::adjust), on top of whatever moved since. Lines
 * nobody counted change nothing. One open count per branch and industry.
 */
class StockCounts
{
    public function __construct(
        private readonly Stock $stock,
        private readonly DocumentNumbers $numbers,
        private readonly Industry $industry,
    ) {}

    /**
     * @throws ValidationException
     */
    public function start(string $branchId, ?string $category, ?string $note, User $by): StockCount
    {
        return DB::transaction(function () use ($branchId, $category, $note, $by) {
            $open = StockCount::query()
                ->where('branch_id', $branchId)
                ->where('application', $this->industry->code())
                ->where('status', StockCount::OPEN)
                ->lockForUpdate()
                ->first();
            if ($open !== null) {
                throw ValidationException::withMessages(['count' => "Count {$open->number} is still open: complete or cancel it first."]);
            }

            $batches = Batch::query()
                ->where('branch_id', $branchId)
                ->where('quantity_on_hand', '>', 0)
                ->whereHas('item', fn ($item) => $item
                    ->ofKind($this->industry->itemKinds())
                    ->when($category !== null, fn ($items) => $items->where('category', $category)))
                ->get();
            if ($batches->isEmpty()) {
                throw ValidationException::withMessages(['category' => $category === null ? 'There is no stock here to count.' : "There is no stock in {$category} here to count."]);
            }

            $count = StockCount::query()->create([
                'branch_id' => $branchId,
                'application' => $this->industry->code(),
                'number' => $this->numbers->next('stock_count', 'C', 5),
                'category' => $category,
                'note' => $note,
                'status' => StockCount::OPEN,
                'started_by' => $by->id,
                'started_at' => now(),
            ]);

            foreach ($batches as $batch) {
                $count->lines()->create([
                    'item_id' => $batch->item_id,
                    'batch_id' => $batch->id,
                    'expected' => $batch->quantity_on_hand,
                ]);
            }

            return $count;
        });
    }

    /**
     * Counts entered for some lines (line id => quantity; null clears it).
     * Each records what is on hand now, the moment it was counted.
     *
     * @param  array<string, int|null>  $counts
     *
     * @throws ValidationException
     */
    public function record(StockCount $count, array $counts, User $by): StockCount
    {
        return DB::transaction(function () use ($count, $counts, $by) {
            $locked = $this->open($count);

            $lines = $locked->lines()->whereIn('id', array_keys($counts))->get()->keyBy('id');
            foreach ($counts as $lineId => $counted) {
                $line = $lines->get($lineId) ?? throw ValidationException::withMessages(["counts.{$lineId}" => 'Not a line of this count.']);
                if ($counted !== null && ($counted < 0 || $counted > 1_000_000)) {
                    throw ValidationException::withMessages(["counts.{$lineId}" => 'A count is between 0 and 1,000,000.']);
                }

                $onHand = (int) Batch::query()->whereKey($line->batch_id)->value('quantity_on_hand');
                $line->forceFill($counted === null
                    ? ['counted' => null, 'counted_by' => null, 'counted_at' => null, 'expected' => $onHand]
                    : ['counted' => $counted, 'counted_by' => $by->id, 'counted_at' => now(), 'expected' => $onHand])
                    ->save();
            }

            return $locked;
        });
    }

    /**
     * Posts the differences of the counted lines, and closes the count.
     *
     * @throws ValidationException
     */
    public function complete(StockCount $count, User $by): StockCount
    {
        return DB::transaction(function () use ($count, $by) {
            $locked = $this->open($count);
            $counted = $locked->lines()->whereNotNull('counted')->with('batch')->get();
            if ($counted->isEmpty()) {
                throw ValidationException::withMessages(['count' => 'Nothing has been counted yet.']);
            }

            foreach ($counted as $line) {
                $difference = (int) $line->counted - $line->expected;
                if ($difference !== 0) {
                    // Never below zero: sold since it was counted, there is less to take off.
                    $change = max($difference, -$line->batch->fresh()->quantity_on_hand);
                    if ($change !== 0) {
                        $this->stock->adjust($line->batch, $change, AdjustmentReason::CountCorrection, "Count {$locked->number}", $by, 'stock_count', $locked->id);
                    }
                    $difference = $change;
                }
                $line->forceFill(['difference' => $difference])->save();
            }

            return $this->close($locked, StockCount::COMPLETED, $by);
        });
    }

    /**
     * @throws ValidationException
     */
    public function cancel(StockCount $count, User $by): StockCount
    {
        return DB::transaction(fn () => $this->close($this->open($count), StockCount::CANCELLED, $by));
    }

    /**
     * @throws ValidationException
     */
    private function open(StockCount $count): StockCount
    {
        $locked = StockCount::query()->whereKey($count->id)->lockForUpdate()->firstOrFail();
        if (! $locked->isOpen()) {
            throw ValidationException::withMessages(['count' => "Count {$locked->number} is {$locked->status}."]);
        }

        return $locked;
    }

    private function close(StockCount $count, string $status, User $by): StockCount
    {
        $count->forceFill(['status' => $status, 'closed_by' => $by->id, 'closed_at' => now()])->save();

        return $count;
    }
}
