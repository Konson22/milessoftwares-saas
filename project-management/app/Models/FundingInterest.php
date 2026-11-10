<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A donor's expression of interest in a funding listing: a message and a way to
 * reach them, answered by the organization that listed it. Never a grant, a
 * commitment or confirmed funding, whatever its status. Crosses two
 * organizations like a partnership, so it is not organization-scoped: every
 * read starts from the donor or the organization in hand. Written by
 * App\Actions\FundingInterests.
 *
 * @property string $id
 * @property string $donor_organization_id
 * @property string $organization_id
 * @property string $funding_listing_id
 * @property string $contact_user_id
 * @property string $contact_name
 * @property string $contact_email
 * @property string $message
 * @property string $status
 * @property string|null $response
 * @property CarbonImmutable $submitted_at
 * @property CarbonImmutable|null $responded_at
 * @property string|null $responded_by
 * @property string|null $partnership_id
 * @property-read Organization $donor
 * @property-read Organization $organization
 * @property-read FundingListing $listing
 * @property-read Partnership|null $partnership
 */
class FundingInterest extends Model
{
    use HasUuids;

    public const SUBMITTED = 'submitted';

    public const UNDER_REVIEW = 'under_review';

    public const ACCEPTED = 'accepted';

    public const DECLINED = 'declined';

    public const STATUSES = [
        self::SUBMITTED => 'Submitted',
        self::UNDER_REVIEW => 'Under review',
        self::ACCEPTED => 'Accepted',
        self::DECLINED => 'Declined',
    ];

    /** Still open: a donor has at most one of these per listing. */
    public const OPEN = [self::SUBMITTED, self::UNDER_REVIEW, self::ACCEPTED];

    /** Where each status may go next. */
    public const NEXT = [
        self::SUBMITTED => [self::UNDER_REVIEW, self::ACCEPTED, self::DECLINED],
        self::UNDER_REVIEW => [self::ACCEPTED, self::DECLINED],
        self::ACCEPTED => [],
        self::DECLINED => [],
    ];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['submitted_at' => 'immutable_datetime', 'responded_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function donor(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'donor_organization_id');
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Across organizations: the listing belongs to the organization, not the
     * donor reading it.
     *
     * @return BelongsTo<FundingListing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(FundingListing::class, 'funding_listing_id')->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<Partnership, $this>
     */
    public function partnership(): BelongsTo
    {
        return $this->belongsTo(Partnership::class);
    }

    public function statusText(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function canMoveTo(string $status): bool
    {
        return in_array($status, self::NEXT[$this->status] ?? [], true);
    }
}
