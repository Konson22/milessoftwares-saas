<?php

namespace App\Logbook\Models;

use App\Logbook\Models\Concerns\TenantModel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Operational work given to one person (ADR-0026 §2.4): pending, in
 * progress, then completed, or awaiting verification first when the task
 * asks for it (verified: completed; or returned). Cancelled and reopened;
 * never deleted. Overdue is derived from due_at.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $reference
 * @property string $title
 * @property string|null $description
 * @property string $priority
 * @property string $status
 * @property string|null $logbook_id
 * @property string|null $log_sheet_id
 * @property string|null $log_entry_id
 * @property string|null $exception_id
 * @property string|null $resource_id
 * @property string|null $series_id
 * @property CarbonImmutable|null $occurrence_on
 * @property string $assignee_id
 * @property string $created_by
 * @property bool $verification_required
 * @property bool $evidence_required
 * @property CarbonImmutable|null $starts_at
 * @property CarbonImmutable|null $due_at
 * @property list<string>|null $reminders
 * @property CarbonImmutable $assigned_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $completion_received_at
 * @property list<string>|null $reminder_channels
 * @property CarbonImmutable|null $completion_received_at
 * @property list<string>|null $reminder_channels
 * @property string|null $completed_by
 * @property string|null $completion_notes
 * @property string|null $completion_entry_id
 * @property string|null $completion_key
 * @property CarbonImmutable|null $verified_at
 * @property string|null $verified_by
 * @property string|null $return_comment
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancelled_by
 * @property string|null $cancel_reason
 * @property CarbonImmutable|null $reopened_at
 * @property int $revision
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $assignee
 * @property-read User $creator
 * @property-read Logbook|null $logbook
 * @property-read LogSheet|null $sheet
 * @property-read Entry|null $entry
 * @property-read Entry|null $completionEntry
 * @property-read LogException|null $exception
 * @property-read LogResource|null $resource
 * @property-read TaskSeries|null $series
 */
class Task extends Model
{
    use TenantModel;

    public const STATUSES = ['pending', 'in_progress', 'awaiting_verification', 'returned', 'completed', 'cancelled'];

    /** Still to be done by the assignee: these can be overdue. */
    public const OPEN = ['pending', 'in_progress', 'returned'];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    protected $table = 'log_tasks';

    protected $guarded = ['id', 'organization_id'];

    protected $attributes = ['status' => 'pending', 'priority' => 'normal', 'revision' => 1, 'verification_required' => false];

    protected function casts(): array
    {
        return [
            'verification_required' => 'boolean',
            'evidence_required' => 'boolean',
            'revision' => 'integer',
            'reminders' => 'array',
            'reminder_channels' => 'array',
            'completion_received_at' => 'immutable_datetime',
            'occurrence_on' => 'immutable_date',
            'starts_at' => 'immutable_datetime',
            'due_at' => 'immutable_datetime',
            'assigned_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'reopened_at' => 'immutable_datetime',
        ];
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function overdue(?CarbonImmutable $now = null): bool
    {
        return $this->isOpen() && $this->due_at !== null && $this->due_at->lt($now ?? CarbonImmutable::now());
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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

    /** @return BelongsTo<Entry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(Entry::class, 'log_entry_id');
    }

    /** @return BelongsTo<Entry, $this> */
    public function completionEntry(): BelongsTo
    {
        return $this->belongsTo(Entry::class, 'completion_entry_id');
    }

    /** @return BelongsTo<LogException, $this> */
    public function exception(): BelongsTo
    {
        return $this->belongsTo(LogException::class, 'exception_id');
    }

    /** @return BelongsTo<LogResource, $this> */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(LogResource::class, 'resource_id');
    }

    /** @return BelongsTo<TaskSeries, $this> */
    public function series(): BelongsTo
    {
        return $this->belongsTo(TaskSeries::class, 'series_id');
    }

    /** @return HasMany<WorkFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(WorkFile::class, 'task_id')->orderBy('created_at');
    }
}
