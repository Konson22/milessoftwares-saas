<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Receipts accounting for an advance. Written as a draft, submitted, then
 * approved by someone else (when they become expenses) or returned to draft
 * with a reason.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $advance_id
 * @property string $status
 * @property CarbonImmutable|null $submitted_at
 * @property string|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $returned_reason
 * @property-read StaffAdvance $advance
 * @property-read User|null $decider
 */
class AdvanceRetirement extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    public const DRAFT = 'draft';

    public const SUBMITTED = 'submitted';

    public const APPROVED = 'approved';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['submitted_at' => 'immutable_datetime', 'decided_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<StaffAdvance, $this>
     */
    public function advance(): BelongsTo
    {
        return $this->belongsTo(StaffAdvance::class, 'advance_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * @return HasMany<AdvanceRetirementLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(AdvanceRetirementLine::class, 'retirement_id');
    }
}
