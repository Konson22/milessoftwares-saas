<?php

namespace App\Modules\Procurement\Models;

use App\Modules\Pharmacy\Models\Medicine;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line of a purchase order. Tenant-safe only through its order.
 *
 * @property string $id
 * @property string $purchase_order_id
 * @property string $medicine_id
 * @property int $quantity_ordered
 * @property int $quantity_received
 * @property int $unit_cost_minor
 * @property-read Medicine $medicine
 */
class PurchaseOrderLine extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['purchase_order_id', 'quantity_received'];

    protected function casts(): array
    {
        return [
            'quantity_ordered' => 'integer',
            'quantity_received' => 'integer',
            'unit_cost_minor' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Medicine, $this>
     */
    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    public function remaining(): int
    {
        return max(0, $this->quantity_ordered - $this->quantity_received);
    }
}
