<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A project the organization shows donors as seeking funding. It points at the
 * project, but holds its own public copy: the project, its budget and grants
 * stay private. Target and confirmed amounts are what the organization states
 * publicly; interest a donor shows is neither. Whether it is published and how
 * funded it is are set apart. Written by App\Actions\Marketplace.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $project_id
 * @property string $title
 * @property string|null $summary
 * @property string|null $sector
 * @property string|null $country_code
 * @property string|null $location
 * @property string|null $objectives
 * @property string|null $outcomes
 * @property CarbonImmutable|null $start_on
 * @property CarbonImmutable|null $end_on
 * @property string $currency
 * @property int $target_minor
 * @property int $confirmed_minor
 * @property string $publication_status
 * @property string $funding_status
 * @property CarbonImmutable|null $published_at
 * @property string $created_by
 * @property-read Organization $organization
 * @property-read Project $project
 */
class FundingListing extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    public const SEEKING = 'seeking';

    public const PARTIALLY_FUNDED = 'partially_funded';

    public const FUNDED = 'funded';

    public const CLOSED = 'closed';

    /** How funded, by name; apart from whether it is published. */
    public const FUNDING_STATUSES = [
        self::SEEKING => 'Seeking funding',
        self::PARTIALLY_FUNDED => 'Partially funded',
        self::FUNDED => 'Fully funded',
        self::CLOSED => 'Closed',
    ];

    protected $guarded = ['id', 'organization_id', 'project_id', 'publication_status', 'published_at', 'created_by'];

    protected function casts(): array
    {
        return [
            'start_on' => 'immutable_date',
            'end_on' => 'immutable_date',
            'target_minor' => 'integer',
            'confirmed_minor' => 'integer',
            'published_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function isPublished(): bool
    {
        return $this->publication_status === OrganizationProfile::PUBLISHED;
    }

    /**
     * Still to find: never below zero, so confirmed funding over the target
     * is not counted twice.
     */
    public function remainingMinor(): int
    {
        return max(0, $this->target_minor - $this->confirmed_minor);
    }

    /**
     * Listings anyone in the marketplace may see: published, by an active
     * organization whose profile is published. Across organizations on purpose.
     *
     * @return Builder<static>
     */
    public static function visible(): Builder
    {
        return self::acrossOrganizations()
            ->where('publication_status', OrganizationProfile::PUBLISHED)
            ->whereIn('organization_id', OrganizationProfile::visible()->select('organization_id'));
    }
}
