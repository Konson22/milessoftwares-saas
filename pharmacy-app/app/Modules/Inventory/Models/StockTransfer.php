<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Stock on its way from one branch to another: out of the sender's batches
 * when sent, into the receiver's when received, back where it was if the
 * sender cancels it first.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $number
 * @property string $from_branch_id
 * @property string $application the industry module that made it (pharmacy, retail)
 * @property string $to_branch_id
 * @property string $status
 * @property string|null $note
 * @property string $sent_by
 * @property CarbonImmutable $sent_at
 * @property string|null $received_by
 * @property CarbonImmutable|null $received_at
 * @property string|null $cancelled_by
 * @property CarbonImmutable|null $cancelled_at
 * @property-read User $sender
 * @property-read User|null $receiver
 * @property-read User|null $canceller
 */
class StockTransfer extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const IN_TRANSIT = 'in_transit';

    public const RECEIVED = 'received';

    public const CANCELLED = 'cancelled';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'sent_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<StockTransferLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(StockTransferLine::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
