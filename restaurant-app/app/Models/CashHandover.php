<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * The cash of a closed till, handed by the cashier to a manager, who
 * counts it and confirms what they received (App\Actions\Handovers).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $number
 * @property string $till_shift_id
 * @property string $status pending, received
 * @property int $amount_minor
 * @property string|null $note
 * @property string $handed_by
 * @property CarbonImmutable $handed_at
 * @property string|null $received_by
 * @property CarbonImmutable|null $received_at
 * @property int|null $received_minor
 * @property string|null $receiving_note
 * @property-read TillShift $till
 * @property-read User $hander
 * @property-read User|null $receiver
 */
class CashHandover extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const PENDING = 'pending';

    public const RECEIVED = 'received';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'received_minor' => 'integer',
            'handed_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    /** Received less (negative) or more than was handed over; null until received. */
    public function differenceMinor(): ?int
    {
        return $this->received_minor === null ? null : $this->received_minor - $this->amount_minor;
    }

    /**
     * @return BelongsTo<TillShift, $this>
     */
    public function till(): BelongsTo
    {
        return $this->belongsTo(TillShift::class, 'till_shift_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function hander(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handed_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
