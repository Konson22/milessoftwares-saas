<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Money given to someone ahead of spending it. An asset until receipts are
 * approved. Changes go through App\Actions\StaffAdvances.
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $payment_method bank_transfer, cash or mobile_money (what Finance is told)
 * @property int $number
 * @property string $holder_id
 * @property int $amount_minor
 * @property string $currency
 * @property string $purpose
 * @property string $status
 * @property string|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_note
 * @property string|null $disbursed_by
 * @property CarbonImmutable|null $disbursed_on
 * @property string|null $reference
 * @property CarbonImmutable|null $due_on
 * @property CarbonImmutable|null $settled_at
 * @property CarbonImmutable $created_at
 * @property-read User $holder
 * @property-read User|null $decider
 * @property-read User|null $disburser
 */
class StaffAdvance extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    public const REQUESTED = 'requested';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    public const DISBURSED = 'disbursed';

    public const SETTLED = 'settled';

    /** Not yet accounted for: while one of these is open, no new advance. */
    public const OPEN = [self::REQUESTED, self::APPROVED, self::DISBURSED];

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'amount_minor' => 'integer',
            'decided_at' => 'immutable_datetime',
            'disbursed_on' => 'immutable_date',
            'due_on' => 'immutable_date',
            'settled_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * "ADV-12".
     */
    public function reference(): string
    {
        return "ADV-{$this->number}";
    }

    /**
     * Whole days past the deadline, while money is still out.
     */
    public function daysOverdue(CarbonImmutable $today): int
    {
        if ($this->status !== self::DISBURSED || $this->due_on === null || $this->due_on->gte($today)) {
            return 0;
        }

        return (int) $this->due_on->diffInDays($today);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'holder_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function disburser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disbursed_by');
    }

    /**
     * @return HasMany<AdvanceRetirement, $this>
     */
    public function retirements(): HasMany
    {
        return $this->hasMany(AdvanceRetirement::class, 'advance_id');
    }

    /**
     * @return HasMany<AdvanceSettlement, $this>
     */
    public function settlements(): HasMany
    {
        return $this->hasMany(AdvanceSettlement::class, 'advance_id');
    }
}
