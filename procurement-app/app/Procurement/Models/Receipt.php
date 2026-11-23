<?php

namespace App\Procurement\Models;

use App\Procurement\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A delivery received, or a service (or milestone) accepted, against an
 * order. Never edited: a correction is a new receipt.
 *
 * @property string $id
 * @property string $number
 * @property string $purchase_order_id
 * @property string $inspection passed, partial or failed
 * @property string $received_by
 * @property string $received_by_name
 * @property-read PurchaseOrder $order
 * @property-read Collection<int, ReceiptLine> $lines
 */
class Receipt extends Model
{
    use TenantModel;

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['received_on' => 'immutable_date'];
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    /** @return HasMany<ReceiptLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(ReceiptLine::class);
    }
}
