<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What an organization shows donors in the funding marketplace: its own public
 * copy, never read from its private records. Seen by others only while
 * published (App\Support\Marketplace). Written by App\Actions\Marketplace.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $status self::DRAFT, self::PUBLISHED or self::UNPUBLISHED
 * @property string|null $summary
 * @property string|null $description
 * @property string|null $mission
 * @property string|null $vision
 * @property string|null $country_code
 * @property string|null $location
 * @property list<string>|null $operating_areas
 * @property list<string>|null $sectors
 * @property string|null $registration_number
 * @property string|null $registration_authority
 * @property string|null $website
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property string|null $achievements
 * @property string|null $logo_path
 * @property CarbonImmutable|null $published_at
 * @property-read Organization $organization
 */
class OrganizationProfile extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    public const UNPUBLISHED = 'unpublished';

    protected $guarded = ['id', 'organization_id', 'status', 'logo_path', 'published_at', 'updated_by'];

    protected function casts(): array
    {
        return [
            'operating_areas' => 'array',
            'sectors' => 'array',
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

    public function isPublished(): bool
    {
        return $this->status === self::PUBLISHED;
    }

    /**
     * Profiles anyone in the marketplace may see: published, of an active
     * organization. Across organizations on purpose.
     *
     * @return Builder<static>
     */
    public static function visible(): Builder
    {
        return self::acrossOrganizations()
            ->where('status', self::PUBLISHED)
            ->whereHas('organization', fn ($query) => $query->where('status', Organization::ACTIVE));
    }
}
