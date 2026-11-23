<?php

namespace App\Logbook\Models;

use App\Logbook\Models\Concerns\TenantModel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * When a log sheet is expected to be filled in (ADR-0026 §2.6). Each
 * expected entry is an occurrence row, so a missing one is visible.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $logbook_id
 * @property string $log_sheet_id
 * @property string $frequency
 * @property list<int>|null $weekdays
 * @property int|null $day_of_month
 * @property string $opens_time
 * @property list<array{opens: string, due: string}>|null $times ADR-0030: several readings a day; null before
 * @property string $due_time
 * @property int $late_minutes
 * @property string $timezone
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable|null $ends_on
 * @property string|null $responsible_id
 * @property string|null $resource_id ADR-0031: the asset it is for
 * @property string $completion
 * @property list<string>|null $reminders
 * @property bool $active
 * @property CarbonImmutable|null $generate_from
 * @property CarbonImmutable|null $paused_at
 * @property CarbonImmutable|null $ended_at
 * @property list<string>|null $reminder_channels
 * @property CarbonImmutable|null $generate_from
 * @property CarbonImmutable|null $paused_at
 * @property CarbonImmutable|null $ended_at
 * @property list<string>|null $reminder_channels
 * @property string $created_by
 * @property-read LogSheet $sheet
 * @property-read Logbook $logbook
 * @property-read User|null $responsible
 */
class Schedule extends Model
{
    use TenantModel;

    protected $table = 'log_schedules';

    protected $guarded = ['id', 'organization_id'];

    protected $attributes = ['active' => true, 'completion' => 'submitted', 'late_minutes' => 240];

    protected function casts(): array
    {
        return [
            'weekdays' => 'array',
            'times' => 'array',
            'reminders' => 'array',
            'reminder_channels' => 'array',
            'generate_from' => 'immutable_datetime',
            'paused_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'day_of_month' => 'integer',
            'late_minutes' => 'integer',
            'active' => 'boolean',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
        ];
    }

    /** @return BelongsTo<LogSheet, $this> */
    public function sheet(): BelongsTo
    {
        return $this->belongsTo(LogSheet::class, 'log_sheet_id');
    }

    /** @return BelongsTo<Logbook, $this> */
    public function logbook(): BelongsTo
    {
        return $this->belongsTo(Logbook::class);
    }

    /** @return BelongsTo<User, $this> */
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    /** @return HasMany<ScheduleOccurrence, $this> */
    public function occurrences(): HasMany
    {
        return $this->hasMany(ScheduleOccurrence::class, 'schedule_id');
    }

    /** ADR-0031: the asset it is for, when one is named. @return BelongsTo<LogResource, $this> */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(LogResource::class, 'resource_id');
    }
}
