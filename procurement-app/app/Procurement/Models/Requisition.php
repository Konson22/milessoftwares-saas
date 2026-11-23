<?php

namespace App\Procurement\Models;

use App\Models\User;
use App\Procurement\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A purchase requisition: draft → pending_approval → approved / rejected /
 * returned (editable again); cancelled; closed once its buying is done.
 *
 * @property string $id
 * @property string $number
 * @property string $requester_id
 * @property string|null $department_id
 * @property string|null $department_name
 * @property string|null $plan_item_id
 * @property string $title
 * @property string $justification
 * @property string $currency
 * @property int|null $rate_minor
 * @property int $total_minor
 * @property int $total_base_minor
 * @property string $status
 * @property int $revision
 * @property-read User $requester
 * @property-read Collection<int, RequisitionLine> $lines
 */
class Requisition extends Model
{
    use TenantModel;

    public const STATUSES = ['draft', 'pending_approval', 'approved', 'rejected', 'returned', 'cancelled', 'closed'];

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return [
            'needed_by' => 'immutable_date',
            'submitted_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'rate_minor' => 'integer',
            'total_minor' => 'integer',
            'total_base_minor' => 'integer',
            'revision' => 'integer',
        ];
    }

    public function editable(): bool
    {
        return in_array($this->status, ['draft', 'returned'], true);
    }

    /** @return HasMany<RequisitionLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(RequisitionLine::class)->orderBy('line_no');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /** @return BelongsTo<PlanItem, $this> */
    public function planItem(): BelongsTo
    {
        return $this->belongsTo(PlanItem::class);
    }

    /** @return HasMany<SourcingEvent, $this> */
    public function sourcingEvents(): HasMany
    {
        return $this->hasMany(SourcingEvent::class);
    }

    /** @return HasMany<PurchaseOrder, $this> */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }
}
