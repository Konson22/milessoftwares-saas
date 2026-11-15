<?php

namespace App\Mel\Models;

use App\Mel\Models\Concerns\TenantModel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Access to one programme for someone without mel.programmes.all. What they
 * may do there still comes from their platform role; this only says where.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $programme_id
 * @property string $user_id
 * @property string|null $unit_id
 * @property CarbonImmutable|null $periods_from
 * @property CarbonImmutable|null $periods_to
 * @property CarbonImmutable|null $expires_on
 * @property string $granted_by
 * @property CarbonImmutable|null $revoked_at
 * @property string|null $revoked_by
 * @property CarbonImmutable $created_at
 * @property-read Programme $programme
 * @property-read User $user
 * @property-read ProgrammeUnit|null $unit
 * @property-read User $granter
 */
class ProgrammeAccess extends Model
{
    use TenantModel;

    protected $table = 'mel_programme_access';

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return [
            'periods_from' => 'immutable_date',
            'periods_to' => 'immutable_date',
            'expires_on' => 'immutable_date',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /**
     * Grants that are in effect today.
     *
     * @param  Builder<ProgrammeAccess>  $query
     * @return Builder<ProgrammeAccess>
     */
    public function scopeInEffect(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')
            ->where(fn (Builder $query) => $query->whereNull('expires_on')->orWhere('expires_on', '>=', CarbonImmutable::today()->toDateString()));
    }

    /**
     * @return BelongsTo<Programme, $this>
     */
    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<ProgrammeUnit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(ProgrammeUnit::class, 'unit_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function granter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
