<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A request to change a project under way. What it asks never changes once
 * raised: it is decided as written, or withdrawn and raised again. Changes
 * go through App\Actions\ChangeRequests.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $project_id
 * @property int $number
 * @property string $title
 * @property string $description
 * @property CarbonImmutable|null $proposed_due_on
 * @property CarbonImmutable|null $previous_due_on
 * @property int|null $budget_change_minor
 * @property string|null $budget_version_id
 * @property string $status
 * @property string $raised_by
 * @property string|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_note
 * @property-read Project $project
 * @property-read User $raiser
 * @property-read User|null $decider
 * @property-read BudgetVersion|null $budgetVersion
 */
class ChangeRequest extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    public const SUBMITTED = 'submitted';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const WITHDRAWN = 'withdrawn';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'proposed_due_on' => 'immutable_date',
            'previous_due_on' => 'immutable_date',
            'budget_change_minor' => 'integer',
            'decided_at' => 'immutable_datetime',
        ];
    }

    /**
     * "WASH-CR-3".
     */
    public function reference(string $projectCode): string
    {
        return "{$projectCode}-CR-{$this->number}";
    }

    public function isSubmitted(): bool
    {
        return $this->status === self::SUBMITTED;
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function raiser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * @return BelongsTo<BudgetVersion, $this>
     */
    public function budgetVersion(): BelongsTo
    {
        return $this->belongsTo(BudgetVersion::class);
    }
}
