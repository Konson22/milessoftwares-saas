<?php

namespace App\Logbook\Models;

use App\Logbook\Models\Concerns\TenantModel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A route recorded by one person on one phone (ADR-0028). Keyed by the
 * phone's UUID. The summary (distance, moving time, gaps) is worked out
 * on the server from the points received; the points stay as observed.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $user_id
 * @property string $logbook_id
 * @property string $log_sheet_id
 * @property string|null $log_resource_id
 * @property string|null $task_id
 * @property string $source
 * @property string $status
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $ended_at
 * @property int $distance_m
 * @property int $moving_seconds
 * @property int $points_count
 * @property int $rejected_points
 * @property int $gaps_count
 * @property int $gap_seconds
 * @property int $last_seq
 * @property CarbonImmutable|null $last_point_at
 * @property array{south: float, west: float, north: float, east: float}|null $bounds
 * @property string|null $device
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 * @property-read Logbook $logbook
 * @property-read LogSheet $sheet
 * @property-read LogResource|null $resource
 * @property-read Task|null $task
 */
class Track extends Model
{
    use TenantModel;

    public const STATUSES = ['recording', 'paused', 'ended'];

    public const SOURCES = ['phone'];

    protected $table = 'log_tracks';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'last_point_at' => 'immutable_datetime',
            'bounds' => 'array',
            'distance_m' => 'integer',
            'moving_seconds' => 'integer',
            'points_count' => 'integer',
            'rejected_points' => 'integer',
            'gaps_count' => 'integer',
            'gap_seconds' => 'integer',
            'last_seq' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Logbook, $this> */
    public function logbook(): BelongsTo
    {
        return $this->belongsTo(Logbook::class);
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

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return HasMany<TrackPoint, $this> */
    public function points(): HasMany
    {
        return $this->hasMany(TrackPoint::class, 'log_track_id')->orderBy('seq');
    }

    /** @return HasMany<TrackEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(TrackEvent::class, 'log_track_id')->orderBy('seq');
    }

    /** @return HasMany<Entry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class, 'log_track_id');
    }
}
