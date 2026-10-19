<?php

namespace App\Modules\Inventory\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Units of one batch on a transfer, with the batch's number, expiry and
 * cost so it arrives as the same batch.
 *
 * @property string $id
 * @property string $stock_transfer_id
 * @property string $item_id
 * @property string $batch_id
 * @property string|null $received_batch_id
 * @property string $batch_number
 * @property CarbonImmutable|null $expires_on null: an item that does not track expiry
 * @property int $unit_cost_minor
 * @property int $quantity
 * @property-read Item $item
 * @property-read Batch $batch
 */
class StockTransferLine extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'expires_on' => 'immutable_date',
            'unit_cost_minor' => 'integer',
            'quantity' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return BelongsTo<Batch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }
}
