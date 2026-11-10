<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $purchase_order_id
 * @property string $fuel_product_id
 * @property int $quantity_milli
 * @property int $received_milli
 * @property int $unit_cost_minor
 * @property-read FuelProduct $product
 */
class PurchaseLine extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'quantity_milli' => 'integer',
            'received_milli' => 'integer',
            'unit_cost_minor' => 'integer',
        ];
    }

    public function remaining(): int
    {
        return max(0, $this->quantity_milli - $this->received_milli);
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    /**
     * @return BelongsTo<FuelProduct, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(FuelProduct::class, 'fuel_product_id');
    }
}
