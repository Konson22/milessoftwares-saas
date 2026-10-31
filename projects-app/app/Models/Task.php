<?php

namespace App\Models;

use App\Enums\TaskPriority;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Work on a project, moved across the board: TODO, IN_PROGRESS, REVIEW,
 * DONE. A task depends on others (they must be done before it can be).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $project_id
 * @property string $number
 * @property string $title
 * @property string|null $description
 * @property string $status
 * @property TaskPriority $priority
 * @property string|null $assignee_id
 * @property string|null $milestone_id
 * @property CarbonImmutable|null $start_on
 * @property CarbonImmutable|null $due_on
 * @property string|null $estimate_hours
 * @property int $position
 * @property string $created_by
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable $created_at
 * @property-read Project $project
 * @property-read Person|null $assignee
 * @property-read Milestone|null $milestone
 * @property-read User $creator
 */
class Task extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const TODO = 'todo';

    public const IN_PROGRESS = 'in_progress';

    public const REVIEW = 'review';

    public const DONE = 'done';

    public const STATUSES = [self::TODO, self::IN_PROGRESS, self::REVIEW, self::DONE];

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'priority' => TaskPriority::class,
            'start_on' => 'date',
            'due_on' => 'date',
            'position' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'assignee_id');
    }

    /**
     * @return BelongsTo<Milestone, $this>
     */
    public function milestone(): BelongsTo
    {
        return $this->belongsTo(Milestone::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The tasks this one waits for.
     *
     * @return BelongsToMany<Task, $this>
     */
    public function dependencies(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'task_dependencies', 'task_id', 'depends_on_id');
    }

    /**
     * The tasks waiting for this one.
     *
     * @return BelongsToMany<Task, $this>
     */
    public function dependents(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'task_dependencies', 'depends_on_id', 'task_id');
    }

    /**
     * @return HasMany<TaskComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class)->oldest();
    }

    /**
     * @return HasMany<TimeEntry, $this>
     */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    public function isDone(): bool
    {
        return $this->status === self::DONE;
    }

    public function overdue(): bool
    {
        return ! $this->isDone() && $this->due_on !== null && $this->due_on->lt(today());
    }
}
