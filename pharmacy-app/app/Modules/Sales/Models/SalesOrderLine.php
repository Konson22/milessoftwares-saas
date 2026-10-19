<?php

namespace App\Modules\Sales\Models;

use App\Modules\Inventory\Models\Item;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line of a sales order, in packs (or units, when pack_name is null) at a
 * price per pack fixed when quoted. Tenant-safe only through its order.
 *
 * @property string $id
 * @property string $sales_order_id
 * @property string $item_id
 * @property string|null $pack_name
 * @property int $units_per_pack
 * @property int $quantity
 * @property int $quantity_delivered
 * @property int $unit_price_minor
 * @property int $discount_percent
 * @property int $line_total_minor
 * @property-read Item $item
 */
class SalesOrderLine extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['sales_order_id', 'quantity_delivered'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'quantity_delivered' => 'integer',
            'unit_price_minor' => 'integer',
            'discount_percent' => 'integer',
            'line_total_minor' => 'integer',
            'units_per_pack' => 'integer',
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
     * "case of 12", or null when sold by the unit.
     */
    public function packLabel(): ?string
    {
        return $this->pack_name === null ? null : "{$this->pack_name} of {$this->units_per_pack}";
    }

    /**
     * A quantity of this line's packs, in the item's unit.
     */
    public function inUnits(int $packs): int
    {
        return $packs * $this->units_per_pack;
    }

    public function remaining(): int
    {
        return max(0, $this->quantity - $this->quantity_delivered);
    }

    /**
     * What $quantity more of this line comes to after its discount, when
     * $alreadyDelivered went before. Rounded so that the deliveries of a
     * whole line add up to its total exactly.
     */
    public function amountFor(int $quantity, int $alreadyDelivered): int
    {
        return $this->amountUpTo($alreadyDelivered + $quantity) - $this->amountUpTo($alreadyDelivered);
    }

    /**
     * What $quantity of this line comes to after its discount.
     */
    public function amountUpTo(int $quantity): int
    {
        $gross = $quantity * $this->unit_price_minor;

        return $gross - intdiv($gross * $this->discount_percent + 50, 100);
    }
}
