<?php

namespace App\Procurement\Models;

use App\Models\User;
use App\Procurement\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recommended supplier selection: pending_approval → approved / rejected,
 * or withdrawn before a decision.
 *
 * @property string $id
 * @property string $sourcing_event_id
 * @property string $quotation_id
 * @property string $supplier_id
 * @property string $recommended_by
 * @property string $justification
 * @property string|null $deviation_reason
 * @property string $status
 * @property-read SourcingEvent $event
 * @property-read Quotation $quotation
 * @property-read Supplier $supplier
 * @property-read User $recommender
 */
class Award extends Model
{
    use TenantModel;

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['decided_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<SourcingEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(SourcingEvent::class, 'sourcing_event_id');
    }

    /** @return BelongsTo<Quotation, $this> */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recommender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recommended_by');
    }
}
