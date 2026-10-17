<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Inventory\Models\Batch;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Puts a batch on hold so that nothing more of it is sold: quarantined
 * while its quality is in doubt, recalled when the maker or the regulator
 * withdraws it. A quarantined batch can be released; a recalled one leaves
 * only by going back to the supplier or through a disposal.
 */
class BatchHolds
{
    /**
     * @param  Batch::QUARANTINED|Batch::RECALLED  $status
     *
     * @throws ValidationException
     */
    public function hold(Batch $batch, string $status, string $reason, User $by): Batch
    {
        return DB::transaction(function () use ($batch, $status, $reason, $by) {
            $locked = Batch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === Batch::RECALLED) {
                throw ValidationException::withMessages(['hold' => "Batch {$locked->batch_number} is already recalled."]);
            }
            if ($locked->status === $status) {
                throw ValidationException::withMessages(['hold' => "Batch {$locked->batch_number} is already quarantined."]);
            }

            $locked->forceFill([
                'status' => $status,
                'hold_reason' => $reason,
                'held_by' => $by->id,
                'held_at' => now(),
            ])->save();

            return $locked;
        });
    }

    /**
     * @throws ValidationException
     */
    public function release(Batch $batch): Batch
    {
        return DB::transaction(function () use ($batch) {
            $locked = Batch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === Batch::RECALLED) {
                throw ValidationException::withMessages(['hold' => "Batch {$locked->batch_number} is recalled: return it to the supplier or dispose of it."]);
            }
            if ($locked->status !== Batch::QUARANTINED) {
                throw ValidationException::withMessages(['hold' => "Batch {$locked->batch_number} is not on hold."]);
            }

            $locked->forceFill([
                'status' => Batch::AVAILABLE,
                'hold_reason' => null,
                'held_by' => null,
                'held_at' => null,
            ])->save();

            return $locked;
        });
    }
}
