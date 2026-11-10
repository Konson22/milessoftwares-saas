<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A platform member of an organization who has opened Projects there, with
 * the access last seen from the platform (App\Actions\Organizations::enter).
 * Roles are given on the platform.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $user_id
 * @property string $status
 * @property bool $is_owner
 * @property list<string>|null $permissions
 * @property list<string>|null $roles role keys
 * @property string|null $org_unit_id
 * @property string|null $invited_by
 * @property CarbonImmutable|null $joined_at
 * @property CarbonImmutable|null $suspended_at
 * @property-read Organization $organization
 * @property-read User $user
 */
class OrganizationMembership extends Model
{
    use HasUuids;

    public const ACTIVE = 'active';

    public const SUSPENDED = 'suspended';

    protected $guarded = ['id', 'organization_id', 'user_id', 'is_owner', 'permissions', 'roles'];

    protected function casts(): array
    {
        return ['is_owner' => 'boolean', 'permissions' => 'array', 'roles' => 'array', 'joined_at' => 'datetime', 'suspended_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }
}
