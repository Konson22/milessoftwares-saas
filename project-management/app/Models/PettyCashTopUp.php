<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A request to restore a box to its float, covering the payments it lists.
 * Decided by someone who is neither the custodian nor whoever asked, after
 * checking the receipts.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $fund_id
 * @property int $amount_minor
 * @property string $status
 * @property string $requested_by
 * @property string|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_note
 * @property CarbonImmutable $created_at
 * @property-read User $requester
 * @property-read User|null $decider
 * @property-read PettyCashFund $fund
 */
class PettyCashTopUp extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    public const SUBMITTED = 'submitted';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $table = 'petty_cash_top_ups';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'decided_at' => 'immutable_datetime'];
    }

    public function isSubmitted(): bool
    {
        return $this->status === self::SUBMITTED;
    }

    /**
     * @return BelongsTo<PettyCashFund, $this>
     */
    public function fund(): BelongsTo
    {
        return $this->belongsTo(PettyCashFund::class, 'fund_id');
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

    /**
     * @return HasMany<PettyCashPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(PettyCashPayment::class, 'top_up_id');
    }
}
