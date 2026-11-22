<?php

namespace App\Logbook\Models;

use App\Logbook\Models\Concerns\TenantModel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A recurring task (ADR-0026 §2.5): each occurrence becomes its own task,
 * made a day ahead by logbook:operations.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $title
 * @property string|null $description
 * @property string $priority
 * @property string|null $logbook_id
 * @property string|null $log_sheet_id
 * @property string|null $resource_id
 * @property string $assignee_id
 * @property bool $verification_required
 * @property bool $evidence_required
 * @property string $frequency
 * @property list<int>|null $weekdays
 * @property int|null $day_of_month
 * @property string $start_time
 * @property string $due_time
 * @property string $timezone
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable|null $ends_on
 * @property list<string>|null $reminders
 * @property bool $active
 * @property CarbonImmutable|null $generate_from
 * @property CarbonImmutable|null $paused_at
 * @property CarbonImmutable|null $ended_at
 * @property list<string>|null $reminder_channels
 * @property string $created_by
 * @property-read User $assignee
 * @property-read Logbook|null $logbook
 */
class TaskSeries extends Model
{
    use TenantModel;

    protected $table = 'log_task_series';

    protected $guarded = ['id', 'organization_id'];

    protected $attributes = ['active' => true, 'priority' => 'normal', 'verification_required' => false];

    protected function casts(): array
    {
        return [
            'weekdays' => 'array',
            'reminders' => 'array',
            'reminder_channels' => 'array',
            'generate_from' => 'immutable_datetime',
            'paused_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'day_of_month' => 'integer',
            'active' => 'boolean',
            'verification_required' => 'boolean',
            'evidence_required' => 'boolean',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /** @return BelongsTo<Logbook, $this> */
    public function logbook(): BelongsTo
    {
        return $this->belongsTo(Logbook::class);
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'series_id');
    }
}
