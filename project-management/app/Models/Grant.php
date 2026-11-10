<?php

namespace App\Models;

use App\Enums\GrantStatus;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A grant from opportunity to closeout; once awarded, the funding
 * agreement. Its amount is in its own currency. Changes go through
 * App\Actions\Grants, which keeps the money rules.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $funder_id
 * @property string $reference
 * @property string $title
 * @property string|null $description
 * @property GrantStatus $status
 * @property string $currency
 * @property int $amount_minor
 * @property bool $restricted
 * @property bool $portal_enabled the funder's contacts can see this grant
 * @property bool $portal_budget
 * @property bool $portal_spending
 * @property bool $portal_progress
 * @property bool $portal_milestones
 * @property bool $portal_commitments
 * @property bool $portal_tasks
 * @property CarbonImmutable|null $start_on
 * @property CarbonImmutable|null $end_on
 * @property CarbonImmutable|null $applied_on
 * @property CarbonImmutable|null $awarded_on
 * @property CarbonImmutable|null $closed_on
 * @property CarbonImmutable|null $declined_on
 * @property string $created_by
 * @property-read Funder $funder
 */
class Grant extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'status' => GrantStatus::class,
            'amount_minor' => 'integer',
            'restricted' => 'boolean', 'portal_enabled' => 'boolean', 'portal_budget' => 'boolean', 'portal_spending' => 'boolean', 'portal_progress' => 'boolean', 'portal_milestones' => 'boolean', 'portal_commitments' => 'boolean', 'portal_tasks' => 'boolean',
            'start_on' => 'date',
            'end_on' => 'date',
            'applied_on' => 'date',
            'awarded_on' => 'date',
            'closed_on' => 'date',
            'declined_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Funder, $this>
     */
    public function funder(): BelongsTo
    {
        return $this->belongsTo(Funder::class);
    }

    /**
     * @return HasMany<GrantAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(GrantAllocation::class);
    }

    /**
     * @return HasMany<GrantDisbursement, $this>
     */
    public function disbursements(): HasMany
    {
        return $this->hasMany(GrantDisbursement::class)->orderBy('due_on');
    }

    /**
     * @return HasMany<GrantReport, $this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(GrantReport::class)->orderBy('due_on');
    }

    /**
     * @return HasMany<GrantAmendment, $this>
     */
    public function amendments(): HasMany
    {
        return $this->hasMany(GrantAmendment::class)->orderBy('number');
    }

    /**
     * @return HasMany<GrantMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(GrantMessage::class);
    }

    public function allocatedMinor(): int
    {
        return (int) $this->allocations()->sum('amount_minor');
    }

    public function scheduledMinor(): int
    {
        return (int) $this->disbursements()->sum('amount_minor');
    }

    public function receivedMinor(): int
    {
        return (int) $this->disbursements()->whereNotNull('received_on')->sum('amount_minor');
    }
}
