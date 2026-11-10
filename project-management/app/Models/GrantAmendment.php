<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A recorded change to an awarded grant's amount or end date. Never
 * changed or deleted: corrections are a further amendment.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $grant_id
 * @property int $number
 * @property CarbonImmutable $effective_on
 * @property string $reason
 * @property int $amount_before_minor
 * @property int $amount_after_minor
 * @property CarbonImmutable|null $end_before
 * @property CarbonImmutable|null $end_after
 * @property string $recorded_by
 * @property CarbonImmutable $created_at
 * @property-read User $recorder
 */
class GrantAmendment extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'effective_on' => 'date',
            'amount_before_minor' => 'integer',
            'amount_after_minor' => 'integer',
            'end_before' => 'date',
            'end_after' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Grant amendments are never changed.'));
        static::deleting(fn () => throw new LogicException('Grant amendments are never deleted.'));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
