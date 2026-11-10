<?php

namespace App\Models;

use App\Enums\CostCategory;
use App\Enums\ExpenseStatus;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Money spent, or to be spent, on a project other than people's time:
 * requested → approved (committed) → paid, or rejected / cancelled. One
 * row through its life; nothing is deleted. Changes go through
 * App\Actions\Spending.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $project_id
 * @property string|null $grant_id
 * @property CostCategory $category
 * @property string $description
 * @property string|null $supplier
 * @property int $amount_minor
 * @property ExpenseStatus $status
 * @property int $approvals_required
 * @property bool $direct
 * @property CarbonImmutable|null $spent_on
 * @property string $recorded_by
 * @property string|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_note
 * @property string|null $paid_by
 * @property string|null $payment_reference
 * @property string|null $cancelled_by
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable $created_at
 * @property-read User $recorder
 * @property-read User|null $decider
 * @property-read Grant|null $grant
 * @property-read Project $project
 */
class Expense extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'category' => CostCategory::class,
            'status' => ExpenseStatus::class,
            'amount_minor' => 'integer',
            'approvals_required' => 'integer',
            'direct' => 'boolean',
            'spent_on' => 'date',
            'decided_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Spent: paid.
     *
     * @param  Builder<self>  $query
     */
    public function scopePaid(Builder $query): void
    {
        $query->where('status', ExpenseStatus::Paid);
    }

    /**
     * Committed: approved, not yet paid.
     *
     * @param  Builder<self>  $query
     */
    public function scopeCommitted(Builder $query): void
    {
        $query->where('status', ExpenseStatus::Approved);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * @return BelongsTo<Grant, $this>
     */
    public function grant(): BelongsTo
    {
        return $this->belongsTo(Grant::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<ExpenseDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(ExpenseDocument::class);
    }

    /**
     * @return HasMany<ExpenseApproval, $this>
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(ExpenseApproval::class);
    }
}
