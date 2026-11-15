<?php

namespace App\Models;

use App\Access\ProjectVisibilityScope;
use App\Collaboration\Models\Invitation as ProjectInvitation;
use App\Collaboration\Models\Participant;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
 * @property string|null $programme_id
 * @property string $status
 * @property CarbonImmutable|null $start_on
 * @property CarbonImmutable|null $due_on
 * @property string $currency
 * @property int $budget_minor
 * @property int $task_sequence
 * @property string $visibility organization (anyone with projects.view) or team (App\Access\ProjectVisibility)
 * @property string $created_by
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable $created_at
 * @property-read Person|null $manager
 * @property-read Programme|null $programme
 */
class Project extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    public const PLANNING = 'planning';

    public const ACTIVE = 'active';

    public const ON_HOLD = 'on_hold';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::PLANNING, self::ACTIVE, self::ON_HOLD, self::COMPLETED, self::CANCELLED];

    /** Still being worked on (or about to be). */
    public const OPEN = [self::PLANNING, self::ACTIVE, self::ON_HOLD];

    /** Open to everyone in the organization who may see projects: the default. */
    public const VISIBLE_TO_ORGANIZATION = 'organization';

    /** Open to its team, its manager, whoever set it up and holders of projects.all. */
    public const VISIBLE_TO_TEAM = 'team';

    public const VISIBILITIES = [self::VISIBLE_TO_ORGANIZATION, self::VISIBLE_TO_TEAM];

    protected $guarded = ['organization_id'];

    protected $attributes = ['visibility' => self::VISIBLE_TO_ORGANIZATION];

    protected static function booted(): void
    {
        static::addGlobalScope(new ProjectVisibilityScope);
    }

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
     * @return BelongsTo<Programme, $this>
     */
    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    /**
     * @return HasMany<Deliverable, $this>
     */
    public function deliverables(): HasMany
    {
        return $this->hasMany(Deliverable::class);
    }

    /**
     * @return HasMany<ChangeRequest, $this>
     */
    public function changeRequests(): HasMany
    {
        return $this->hasMany(ChangeRequest::class);
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * Where its tasks are kept, in order.
     *
     * @return HasMany<TaskList, $this>
     */
    public function lists(): HasMany
    {
        return $this->hasMany(TaskList::class)->orderBy('position')->orderBy('name');
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
     * Other organizations taking part, now or before (ADR-0014).
     *
     * @return HasMany<Participant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(Participant::class);
    }

    /**
     * Invitations to take part, answered or not.
     *
     * @return HasMany<ProjectInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(ProjectInvitation::class);
    }

    /**
     * @return HasMany<TimeEntry, $this>
     */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    /**
     * Every line of every budget version; usually you want one version's
     * lines (BudgetVersion::lines).
     *
     * @return HasMany<BudgetLine, $this>
     */
    public function budgetLines(): HasMany
    {
        return $this->hasMany(BudgetLine::class);
    }

    /**
     * @return HasMany<BudgetVersion, $this>
     */
    public function budgetVersions(): HasMany
    {
        return $this->hasMany(BudgetVersion::class)->orderBy('number');
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
     * The grants funding this project, and how much of each.
     *
     * @return HasMany<GrantAllocation, $this>
     */
    public function grantAllocations(): HasMany
    {
        return $this->hasMany(GrantAllocation::class);
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
