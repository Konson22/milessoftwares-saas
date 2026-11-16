<?php

namespace App\Models;

use App\Access\ProjectBound;
use App\Access\VisibleThroughProject;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A supplier's bill against one purchase order (ADR-0020). Recorded in
 * Projects as the evidence Finance pays from; it is not a ledger entry and
 * never marks anything paid. Never edited: a wrong one is voided, with the
 * reason, and stays on record.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $purchase_order_id
 * @property string $supplier_id
 * @property string $number
 * @property string $number_key
 * @property int $amount_minor
 * @property string $currency
 * @property CarbonImmutable $invoice_on
 * @property CarbonImmutable|null $due_on
 * @property string|null $original_name
 * @property string|null $path
 * @property string $status
 * @property string $recorded_by
 * @property CarbonImmutable|null $voided_at
 * @property string|null $voided_by
 * @property string|null $void_reason
 * @property-read User $recorder
 */
class SupplierInvoice extends Model implements ProjectBound
{
    use BelongsToOrganization;
    use HasUuids;
    use VisibleThroughProject;

    public const RECORDED = 'recorded';

    public const VOIDED = 'voided';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'invoice_on' => 'immutable_date',
            'due_on' => 'immutable_date',
            'voided_at' => 'immutable_datetime',
        ];
    }

    /**
     * "INV 001", "inv-001 " and "INV001" are one invoice number.
     */
    public static function keyOf(string $number): string
    {
        return (string) preg_replace('/[\s\-_\/.]+/', '', mb_strtolower(trim($number)));
    }

    /**
     * @param  Builder<SupplierInvoice>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->where('status', self::RECORDED);
    }

    public function isLive(): bool
    {
        return $this->status === self::RECORDED;
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * Seen only with the project its PurchaseOrder belongs to (App\Access\VisibleThroughProject).
     *
     * @return array{0: string, 1: class-string<Model>}
     */
    public function visibleThrough(): array
    {
        return ['purchase_order_id', PurchaseOrder::class];
    }
}
