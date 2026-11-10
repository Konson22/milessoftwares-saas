<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Part of an advance cleared without receipts: cash handed back, or a
 * write-off, which a second person must approve.
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $payment_method bank_transfer, cash or mobile_money (what Finance is told)
 * @property string $advance_id
 * @property string $type
 * @property string $status
 * @property int $amount_minor
 * @property CarbonImmutable $on
 * @property string|null $reason
 * @property string $recorded_by
 * @property string|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_note
 * @property-read User $recorder
 * @property-read User|null $decider
 */
class AdvanceSettlement extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    public const RETURN = 'return';

    public const WRITE_OFF = 'write_off';

    public const SUBMITTED = 'submitted';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'on' => 'immutable_date', 'decided_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
