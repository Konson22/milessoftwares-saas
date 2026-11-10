<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A petty cash box: a fixed float held by a named custodian, in its own cash
 * account. Its balance is that account's, from the ledger. Changes go through
 * App\Actions\PettyCash.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string $custodian_id
 * @property int $float_minor
 * @property string $currency
 * @property string $status
 * @property CarbonImmutable|null $closed_at
 * @property CarbonImmutable|null $moved_at
 * @property string $created_by
 * @property-read User $custodian
 */
class PettyCashFund extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    public const ACTIVE = 'active';

    public const CLOSED = 'closed';

    /** Moved into a Finance petty cash fund: kept here, read only. */
    public const MOVED = 'moved';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['float_minor' => 'integer', 'closed_at' => 'immutable_datetime', 'moved_at' => 'immutable_datetime'];
    }

    public function isMoved(): bool
    {
        return $this->status === self::MOVED;
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function custodian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'custodian_id');
    }

    /**
     * @return HasMany<PettyCashPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(PettyCashPayment::class, 'fund_id');
    }

    /**
     * @return HasMany<PettyCashTopUp, $this>
     */
    public function topUps(): HasMany
    {
        return $this->hasMany(PettyCashTopUp::class, 'fund_id');
    }

    /**
     * @return HasMany<PettyCashCount, $this>
     */
    public function counts(): HasMany
    {
        return $this->hasMany(PettyCashCount::class, 'fund_id');
    }
}
