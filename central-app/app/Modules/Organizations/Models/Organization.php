<?php

namespace App\Modules\Organizations\Models;

use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Organizations\Enums\OrganizationStatus;
use App\Support\Hosts\PlatformHosts;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A customer business. The UUID is the immutable tenant identifier; slugs are
 * presentation and may change.
 *
 * @property string $id
 * @property string $legal_name
 * @property string $display_name
 * @property string|null $business_type
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property string|null $address
 * @property string|null $billing_email
 * @property string|null $billing_address
 * @property string|null $tax_id
 * @property string $country_code
 * @property string $default_currency
 * @property string $timezone
 * @property OrganizationStatus $status
 * @property string|null $owner_membership_id
 * @property string|null $created_by_user_id
 * @property Carbon|null $activated_at
 * @property Carbon|null $suspended_at
 * @property Carbon|null $archived_at
 * @property Carbon|null $purge_after
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read OrganizationSlug|null $primarySlug
 */
#[Fillable([
    'legal_name', 'display_name', 'business_type', 'contact_email', 'contact_phone',
    'address', 'country_code', 'default_currency', 'timezone',
])]
#[UseFactory(OrganizationFactory::class)]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrganizationStatus::class,
            'activated_at' => 'datetime',
            'suspended_at' => 'datetime',
            'archived_at' => 'datetime',
            'purge_after' => 'datetime',
        ];
    }

    /**
     * @return HasMany<OrganizationSlug, $this>
     */
    public function slugs(): HasMany
    {
        return $this->hasMany(OrganizationSlug::class);
    }

    /**
     * @return HasOne<OrganizationSlug, $this>
     */
    public function primarySlug(): HasOne
    {
        return $this->hasOne(OrganizationSlug::class)->where('is_primary', true);
    }

    /**
     * Memberships are tenant-scoped; this relation is for code that already
     * runs inside this organization's tenant context.
     *
     * @return HasMany<OrganizationMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    public function workspaceUrl(string $path = '/'): ?string
    {
        $slug = $this->primarySlug?->slug;

        return $slug === null ? null : PlatformHosts::url(PlatformHosts::workspace($slug), $path);
    }
}
