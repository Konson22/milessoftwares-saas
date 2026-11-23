<?php

namespace App\Logbook\Models;

use App\Logbook\Models\Concerns\TenantModel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A replaced, reset or corrected odometer or hour meter (ADR-0030 §4.8):
 * the reading it shows right after, so a start reading lower than the
 * last end reading is accepted from then on. Append-only.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $log_resource_id
 * @property 'odometer'|'hour_meter' $measure
 * @property 'replaced'|'reset'|'corrected' $kind
 * @property string $reading decimal
 * @property CarbonImmutable $occurred_at
 * @property string $reason
 * @property string $created_by
 * @property CarbonImmutable|null $created_at
 * @property-read LogResource $resource
 * @property-read User $creator
 */
class MeterEvent extends Model
{
    use TenantModel;

    public const UPDATED_AT = null;

    public const MEASURES = ['odometer', 'hour_meter'];

    public const KINDS = ['replaced', 'reset', 'corrected'];

    protected $table = 'log_resource_meter_events';

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['reading' => 'decimal:1', 'occurred_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<LogResource, $this> */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(LogResource::class, 'log_resource_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
