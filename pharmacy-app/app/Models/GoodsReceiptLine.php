<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line of a goods received note. Tenant-safe only through its receipt.
 *
 * @property string $id
 * @property string $goods_receipt_id
 * @property string $medicine_id
 * @property string $batch_id
 * @property string|null $purchase_order_line_id
 * @property string $batch_number
 * @property CarbonImmutable $expires_on
 * @property int $quantity
 * @property int $unit_cost_minor
 * @property int $line_total_minor
 * @property-read Medicine $medicine
 */
class GoodsReceiptLine extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['goods_receipt_id'];

    protected function casts(): array
    {
        return [
            'expires_on' => 'immutable_date',
            'quantity' => 'integer',
            'unit_cost_minor' => 'integer',
            'line_total_minor' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Medicine, $this>
     */
    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    /**
     * @return BelongsTo<Batch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }
}
