<?php

namespace App\Logbook\Models;

use App\Logbook\Models\Concerns\TenantModel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person an asset is assigned to, for a run of days (ADR-0032 §3): a
 * vehicle's driver. Set by managers; ended, never deleted.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $log_resource_id
 * @property string $user_id
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable|null $ends_on inclusive; null: open
 * @property string|null $note
 * @property string $created_by
 * @property CarbonImmutable|null $ended_at
 * @property string|null $ended_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read LogResource $resource
 * @property-read User $user
 * @property-read User $creator
 */
class ResourceAssignment extends Model
{
    use TenantModel;

    protected $table = 'log_resource_assignments';

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'ended_at' => 'immutable_datetime',
        ];
    }

    /** Covers the day (Y-m-d, workspace time). */
    public function covers(string $day): bool
    {
        return $this->starts_on->toDateString() <= $day && ($this->ends_on === null || $this->ends_on->toDateString() >= $day);
    }

    /** @return BelongsTo<LogResource, $this> */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(LogResource::class, 'log_resource_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
