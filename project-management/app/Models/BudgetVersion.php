<?php

namespace App\Models;

use App\Enums\BudgetVersionStatus;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One version of a project's budget. Only a draft's lines change; an
 * approved version is the budget spending is checked against, until a
 * later version is approved. Changes go through App\Actions\Budgets.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $project_id
 * @property int $number
 * @property BudgetVersionStatus $status
 * @property int $total_minor
 * @property string|null $note
 * @property string|null $submitted_by
 * @property CarbonImmutable|null $submitted_at
 * @property string|null $approved_by
 * @property CarbonImmutable|null $approved_at
 * @property string|null $returned_reason
 * @property string $created_by
 * @property-read User|null $submitter
 * @property-read User|null $approver
 */
class BudgetVersion extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'status' => BudgetVersionStatus::class,
            'number' => 'integer',
            'total_minor' => 'integer',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<BudgetLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(BudgetLine::class);
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
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isDraft(): bool
    {
        return $this->status === BudgetVersionStatus::Draft;
    }
}
