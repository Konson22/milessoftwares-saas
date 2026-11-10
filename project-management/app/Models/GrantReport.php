<?php

namespace App\Models;

use App\Enums\GrantReportStatus;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A report the funder requires (narrative, financial or both) and where it
 * stands.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $grant_id
 * @property string $title
 * @property string $kind
 * @property CarbonImmutable $due_on
 * @property GrantReportStatus $status
 * @property CarbonImmutable|null $submitted_on
 * @property CarbonImmutable|null $accepted_on
 * @property string|null $notes
 * @property-read Grant $grant
 */
class GrantReport extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    public const KINDS = ['narrative' => 'Narrative', 'financial' => 'Financial', 'combined' => 'Narrative and financial'];

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['status' => GrantReportStatus::class, 'due_on' => 'date', 'submitted_on' => 'date', 'accepted_on' => 'date'];
    }

    /**
     * @return BelongsTo<Grant, $this>
     */
    public function grant(): BelongsTo
    {
        return $this->belongsTo(Grant::class);
    }

    /**
     * @return HasMany<GrantReportDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(GrantReportDocument::class);
    }

    public function isOverdue(): bool
    {
        return $this->status->isOutstanding() && $this->due_on->lt(today());
    }
}
