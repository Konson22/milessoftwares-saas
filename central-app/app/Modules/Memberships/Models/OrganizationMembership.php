<?php

namespace App\Modules\Memberships\Models;

use App\Models\User;
use App\Modules\Memberships\Enums\MembershipStatus;
use App\Support\Tenancy\BelongsToOrganization;
use Database\Factories\OrganizationMembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $user_id
 * @property string|null $position_id
 * @property MembershipStatus $status
 * @property Carbon|null $joined_at
 * @property Carbon|null $suspended_at
 * @property Carbon|null $removed_at
 * @property-read User $user
 */
#[Fillable(['position_id'])]
#[UseFactory(OrganizationMembershipFactory::class)]
class OrganizationMembership extends Model
{
    /** @use HasFactory<OrganizationMembershipFactory> */
    use BelongsToOrganization, HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MembershipStatus::class,
            'joined_at' => 'datetime',
            'suspended_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
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
        return $this->status === MembershipStatus::Active;
    }
}
