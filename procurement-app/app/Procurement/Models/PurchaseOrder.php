<?php

namespace App\Procurement\Models;

use App\Models\User;
use App\Procurement\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An authorized purchasing commitment: draft → pending_approval → approved
 * → issued → partially_received → received → closed; cancelled while
 * nothing has arrived. Amending an approved or issued order snapshots it
 * and sends it back to draft (a new revision needs approval again).
 *
 * A purchase order is a commitment, never expenditure: reports keep
 * committed, invoiced and paid apart.
 *
 * @property string $id
 * @property string $number
 * @property string $supplier_id
 * @property string|null $requisition_id
 * @property string|null $sourcing_event_id
 * @property string|null $award_id
 * @property string $method
 * @property string $currency
 * @property int|null $rate_minor
 * @property int $subtotal_minor
 * @property int $discount_minor
 * @property int $tax_minor
 * @property int $delivery_minor
 * @property int $total_minor
 * @property int $total_base_minor
 * @property string $status
 * @property int $revision
 * @property string $prepared_by
 * @property-read Supplier $supplier
 * @property-read Requisition|null $requisition
 * @property-read Collection<int, PurchaseOrderLine> $lines
 * @property-read Collection<int, Receipt> $receipts
 * @property-read Collection<int, SupplierInvoice> $invoices
 */
class PurchaseOrder extends Model
{
    use TenantModel;

    public const STATUSES = ['draft', 'pending_approval', 'approved', 'issued', 'partially_received', 'received', 'closed', 'cancelled'];

    /** Committed to the supplier: counted as committed in reports. */
    public const COMMITTED = ['approved', 'issued', 'partially_received', 'received', 'closed'];

    /** Goods and services may be received against it. */
    public const RECEIVABLE = ['issued', 'partially_received'];

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return [
            'delivery_date' => 'immutable_date',
            'approved_at' => 'immutable_datetime',
            'issued_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'rate_minor' => 'integer',
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'tax_minor' => 'integer',
            'delivery_minor' => 'integer',
            'total_minor' => 'integer',
            'total_base_minor' => 'integer',
            'revision' => 'integer',
        ];
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<Requisition, $this> */
    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    /** @return BelongsTo<SourcingEvent, $this> */
    public function sourcingEvent(): BelongsTo
    {
        return $this->belongsTo(SourcingEvent::class);
    }

    /** @return BelongsTo<User, $this> */
    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    /** @return HasMany<PurchaseOrderLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class)->orderBy('line_no');
    }

    /** @return HasMany<Receipt, $this> */
    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class)->orderBy('received_on')->orderBy('created_at');
    }

    /** @return HasMany<SupplierInvoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(SupplierInvoice::class)->orderBy('invoice_date');
    }

    /** @return HasMany<PurchaseOrderRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(PurchaseOrderRevision::class)->orderBy('revision');
    }
}
