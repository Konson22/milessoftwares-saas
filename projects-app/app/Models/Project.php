<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A project: PLANNING, ACTIVE, ON_HOLD, then COMPLETED or CANCELLED. Its
 * tasks are numbered CODE-1, CODE-2... (App\Actions\Tasks).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string|null $client
 * @property string|null $manager_id
 * @property string $status
 * @property CarbonImmutable|null $start_on
 * @property CarbonImmutable|null $due_on
 * @property string $currency
 * @property int $budget_minor
 * @property int $task_sequence
 * @property string $created_by
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable $created_at
 * @property-read Person|null $manager
 */
class Project extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const PLANNING = 'planning';

    public const ACTIVE = 'active';

    public const ON_HOLD = 'on_hold';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::PLANNING, self::ACTIVE, self::ON_HOLD, self::COMPLETED, self::CANCELLED];

    /** Still being worked on (or about to be). */
    public const OPEN = [self::PLANNING, self::ACTIVE, self::ON_HOLD];

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'start_on' => 'date',
            'due_on' => 'date',
            'budget_minor' => 'integer',
            'task_sequence' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'manager_id');
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * @return HasMany<Milestone, $this>
     */
    public function milestones(): HasMany
    {
        return $this->hasMany(Milestone::class)->orderBy('due_on');
    }

    /**
     * @return HasMany<ProjectMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    /**
     * @return HasMany<TimeEntry, $this>
     */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    /**
     * @return HasMany<BudgetLine, $this>
     */
    public function budgetLines(): HasMany
    {
        return $this->hasMany(BudgetLine::class);
    }

    /**
     * @return HasMany<Expense, $this>
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /**
     * @return HasMany<Risk, $this>
     */
    public function risks(): HasMany
    {
        return $this->hasMany(Risk::class);
    }

    /**
     * @return HasMany<Activity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class)->latest('created_at');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    /**
     * Code, name or client containing the text.
     *
     * @param  Builder<self>  $query
     */
    public function scopeSearch(Builder $query, string $text): void
    {
        $text = trim($text);
        if ($text === '') {
            return;
        }

        $like = '%'.mb_strtolower($text).'%';
        $query->where(fn (Builder $query) => $query
            ->whereRaw('lower(code) like ?', [$like])
            ->orWhereRaw('lower(name) like ?', [$like])
            ->orWhereRaw('lower(coalesce(client, \'\')) like ?', [$like]));
    }
}
