<?php

namespace App\Logbook\Models;

use App\Logbook\Models\Concerns\TenantModel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A trip a manager planned (ADR-0032 §4): a vehicle, a driver and a day on
 * a vehicle movement sheet. For that day it counts as an assignment.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $logbook_id
 * @property string $log_sheet_id
 * @property string $log_resource_id
 * @property string $user_id the driver
 * @property CarbonImmutable $planned_on
 * @property string|null $planned_time H:i, workspace time
 * @property string|null $destination
 * @property string|null $purpose
 * @property 'planned'|'started'|'done'|'cancelled' $status
 * @property string|null $log_entry_id
 * @property string $created_by
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancelled_by
 * @property string|null $cancel_reason
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read LogSheet $sheet
 * @property-read LogResource $resource
 * @property-read User $user
 * @property-read User $creator
 * @property-read Entry|null $entry
 */
class Dispatch extends Model
{
    use TenantModel;

    public const STATUSES = ['planned', 'started', 'done', 'cancelled'];

    protected $table = 'log_dispatches';

    protected $guarded = ['id', 'organization_id'];

    protected $attributes = ['status' => 'planned'];

    protected function casts(): array
    {
        return ['planned_on' => 'immutable_date', 'cancelled_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<LogSheet, $this> */
    public function sheet(): BelongsTo
    {
        return $this->belongsTo(LogSheet::class, 'log_sheet_id');
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

    /** @return BelongsTo<Entry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(Entry::class, 'log_entry_id');
    }

    /** Planned or started: the driver may still record it. */
    public function open(): bool
    {
        return in_array($this->status, ['planned', 'started'], true);
    }
}
