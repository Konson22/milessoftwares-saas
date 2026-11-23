<?php

namespace App\Logbook\Models;

use App\Logbook\Models\Concerns\TenantModel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An operational exception (ADR-0026 §2.7): an abnormal reading, a failed
 * check or a reported problem, followed from open to closed. The entry it
 * came from never changes; corrective action is a linked task.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $reference
 * @property string $title
 * @property string|null $description
 * @property string $source
 * @property string $severity
 * @property string $status
 * @property string|null $logbook_id
 * @property string|null $log_sheet_id
 * @property string|null $log_entry_id
 * @property string|null $resource_id
 * @property string|null $field
 * @property string|null $field_label
 * @property string|null $recorded_value
 * @property string|null $expected
 * @property string|null $auto_key
 * @property string|null $raised_by
 * @property string|null $assignee_id
 * @property CarbonImmutable|null $due_at
 * @property string|null $resolution_notes
 * @property CarbonImmutable|null $resolved_at
 * @property string|null $resolved_by
 * @property CarbonImmutable|null $closed_at
 * @property string|null $closed_by
 * @property CarbonImmutable|null $reopened_at
 * @property int $revision
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Logbook|null $logbook
 * @property-read LogSheet|null $sheet
 * @property-read Entry|null $entry
 * @property-read LogResource|null $resource
 * @property-read User|null $assignee
 * @property-read User|null $raiser
 */
class LogException extends Model
{
    use TenantModel;

    public const STATUSES = ['open', 'in_progress', 'resolved', 'closed'];

    public const OPEN = ['open', 'in_progress'];

    public const SEVERITIES = ['low', 'medium', 'high', 'critical'];

    protected $table = 'log_exceptions';

    protected $guarded = ['id', 'organization_id'];

    protected $attributes = ['status' => 'open', 'severity' => 'medium', 'revision' => 1];

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'due_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
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

    /** @return BelongsTo<LogResource, $this> */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(LogResource::class, 'resource_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /** @return BelongsTo<User, $this> */
    public function raiser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'exception_id')->orderBy('created_at');
    }

    /** @return HasMany<WorkFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(WorkFile::class, 'exception_id')->orderBy('created_at');
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /** @return BelongsTo<User, $this> */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
