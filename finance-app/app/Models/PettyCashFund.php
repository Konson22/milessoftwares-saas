<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A petty cash box kept at a fixed float (App\Finance\PettyCash). What it
 * holds is its account's balance in the ledger.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string $account_id
 * @property string|null $custodian_id null until a manager names one (a fund moved in from Projects)
 * @property int $float_minor in the account's currency
 * @property int|null $replenish_at_minor shown as due for a top-up at or below this
 * @property string $status
 * @property string|null $created_by null when moved in from a product
 * @property string|null $source_reference the product's box it came from (projects:petty-cash:{id})
 * @property string|null $closed_by
 * @property CarbonImmutable|null $closed_at
 * @property CarbonImmutable $created_at
 * @property-read Account $account
 * @property-read User|null $custodian
 */
class PettyCashFund extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const ACTIVE = 'active';

    /** No vouchers until it is active again. */
    public const SUSPENDED = 'suspended';

    public const CLOSED = 'closed';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'float_minor' => 'integer',
            'replenish_at_minor' => 'integer',
            'closed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function custodian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'custodian_id');
    }

    /**
     * @return HasMany<PettyCashVoucher, $this>
     */
    public function vouchers(): HasMany
    {
        return $this->hasMany(PettyCashVoucher::class, 'fund_id');
    }

    /**
     * @return HasMany<PettyCashCount, $this>
     */
    public function counts(): HasMany
    {
        return $this->hasMany(PettyCashCount::class, 'fund_id');
    }
}
