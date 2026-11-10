<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Money paid out of a petty cash box, with its receipt. Posted when it is
 * recorded; checked by someone other than the custodian; a rejected one is
 * reversed, never changed.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $fund_id
 * @property string $number
 * @property CarbonImmutable $paid_on
 * @property int $amount_minor in the fund's currency
 * @property string|null $payee
 * @property string $description
 * @property string $account_id
 * @property string|null $cost_centre_id
 * @property string|null $project_id
 * @property string $receipt_name
 * @property string $receipt_path
 * @property string $receipt_mime
 * @property int $receipt_size
 * @property string $status
 * @property string $journal_id
 * @property string|null $reversal_journal_id
 * @property string $recorded_by
 * @property string|null $checked_by
 * @property CarbonImmutable|null $checked_at
 * @property string|null $reject_note
 * @property string|null $replenished_by_id
 * @property CarbonImmutable $created_at
 * @property-read PettyCashFund $fund
 * @property-read Account $account
 * @property-read DimensionValue|null $costCentre
 * @property-read DimensionValue|null $project
 * @property-read User $recorder
 * @property-read User|null $checker
 * @property-read PaymentRequest|null $replenishment
 * @property-read Journal $journal
 */
class PettyCashVoucher extends Model
{
    use BelongsToTenant;
    use HasUuids;

    /** Posted, waiting to be checked. */
    public const RECORDED = 'recorded';

    public const VERIFIED = 'verified';

    /** Reversed: the custodian owes the box the money. */
    public const REJECTED = 'rejected';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'paid_on' => 'immutable_date',
            'amount_minor' => 'integer',
            'receipt_size' => 'integer',
            'checked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<PettyCashFund, $this>
     */
    public function fund(): BelongsTo
    {
        return $this->belongsTo(PettyCashFund::class, 'fund_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<DimensionValue, $this>
     */
    public function costCentre(): BelongsTo
    {
        return $this->belongsTo(DimensionValue::class, 'cost_centre_id');
    }

    /**
     * @return BelongsTo<DimensionValue, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(DimensionValue::class, 'project_id');
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
    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }

    /**
     * @return BelongsTo<PaymentRequest, $this>
     */
    public function replenishment(): BelongsTo
    {
        return $this->belongsTo(PaymentRequest::class, 'replenished_by_id');
    }

    /**
     * @return BelongsTo<Journal, $this>
     */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }
}
