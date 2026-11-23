<?php

namespace App\Procurement\Models;

use App\Procurement\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $purchase_order_id
 * @property int $line_no
 * @property string $kind goods or service
 * @property string $description
 * @property string $quantity
 * @property string|null $unit
 * @property int $unit_price_minor
 * @property int $amount_minor
 * @property string|null $requisition_line_id
 */
class PurchaseOrderLine extends Model
{
    use TenantModel;

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['line_no' => 'integer', 'unit_price_minor' => 'integer', 'amount_minor' => 'integer'];
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    /** @return HasMany<ReceiptLine, $this> */
    public function receiptLines(): HasMany
    {
        return $this->hasMany(ReceiptLine::class);
    }
}
