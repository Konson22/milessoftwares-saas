<?php

namespace App\Models;

use App\FinanceIntegration\RequestStatus;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Spending handed to Finance (integrated mode), with the references both
 * sides keep: the request id, a correlation id, an idempotency key and a
 * revision. Its status moves only through App\FinanceIntegration.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $project_id
 * @property string $expense_id
 * @property string $kind
 * @property int $revision
 * @property string $correlation_id
 * @property string $idempotency_key
 * @property int $contract_version
 * @property string $currency
 * @property int $amount_minor
 * @property RequestStatus $status
 * @property string|null $finance_reference
 * @property int|null $approved_minor
 * @property int $paid_minor
 * @property int $reversed_minor
 * @property int $finance_sequence
 * @property CarbonImmutable $submitted_at
 * @property CarbonImmutable|null $last_event_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Expense $expense
 * @property-read Project $project
 */
class FinanceRequest extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    public const SPENDING = 'spending';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'status' => RequestStatus::class,
            'revision' => 'integer',
            'contract_version' => 'integer',
            'amount_minor' => 'integer',
            'approved_minor' => 'integer',
            'paid_minor' => 'integer',
            'reversed_minor' => 'integer',
            'finance_sequence' => 'integer',
            'submitted_at' => 'immutable_datetime',
            'last_event_at' => 'immutable_datetime',
        ];
    }

    /** What Finance has paid and not taken back. */
    public function netPaidMinor(): int
    {
        return $this->paid_minor - $this->reversed_minor;
    }

    /**
     * @return BelongsTo<Expense, $this>
     */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<FinanceRequestTransition, $this>
     */
    public function transitions(): HasMany
    {
        return $this->hasMany(FinanceRequestTransition::class);
    }

    /**
     * @return HasMany<FinancePayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(FinancePayment::class);
    }
}
