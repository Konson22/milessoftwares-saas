<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use App\Modules\Inventory\Enums\DisposalReason;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Stock to be destroyed, requested by one person and approved (or turned
 * down) by another; approved, it leaves the batch.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $number
 * @property string $item_id
 * @property string $batch_id
 * @property int $quantity
 * @property DisposalReason $reason
 * @property string|null $note
 * @property string $status
 * @property string $requested_by
 * @property CarbonImmutable $requested_at
 * @property string|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_note
 * @property-read Item $item
 * @property-read Batch $batch
 * @property-read User $requester
 * @property-read User|null $decider
 */
class StockDisposal extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'reason' => DisposalReason::class,
            'requested_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
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
     * @return BelongsTo<Batch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
