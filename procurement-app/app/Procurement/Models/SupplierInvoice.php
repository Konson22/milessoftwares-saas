<?php

namespace App\Procurement\Models;

use App\Procurement\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A supplier invoice tracked against an order. A procurement record, not a
 * ledger entry: with Finance connected the liability and its payment are
 * Finance's (finance_status "sent").
 *
 * @property string $id
 * @property string $purchase_order_id
 * @property string $supplier_id
 * @property string $number
 * @property string $number_key
 * @property string $currency
 * @property int $amount_minor
 * @property int $amount_base_minor
 * @property string $match_status matched or awaiting_receipt
 * @property string $status recorded or voided
 * @property string $payment_status unpaid or paid_externally
 * @property string $finance_status none or sent
 * @property-read PurchaseOrder $order
 * @property-read Supplier $supplier
 */
class SupplierInvoice extends Model
{
    use TenantModel;

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'paid_on' => 'immutable_date',
            'finance_sent_at' => 'immutable_datetime',
            'amount_minor' => 'integer',
            'amount_base_minor' => 'integer',
        ];
    }

    public function live(): bool
    {
        return $this->status === 'recorded';
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
