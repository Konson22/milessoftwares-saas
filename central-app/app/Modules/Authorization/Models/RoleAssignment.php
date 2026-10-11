<?php

namespace App\Modules\Authorization\Models;

use App\Modules\Applications\Models\ApplicationRole;
use App\Modules\Authorization\Enums\ScopeType;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One role, in one application, for one member, at exactly one scope.
 *
 * Nothing is mass assignable: assignments are only created through the
 * delegation-checked action introduced in Phase 4.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $membership_id
 * @property string $application_id
 * @property string $application_role_id
 * @property ScopeType $scope_type
 * @property string|null $branch_id
 * @property string|null $department_id
 * @property string|null $resource_type
 * @property string|null $resource_id
 * @property string|null $granted_by_membership_id
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 * @property string|null $revoked_by_membership_id
 * @property-read ApplicationRole $role
 * @property-read OrganizationMembership $membership
 */
class RoleAssignment extends Model
{
    use BelongsToOrganization, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope_type' => ScopeType::class,
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ApplicationRole, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(ApplicationRole::class, 'application_role_id');
    }

    /**
     * @return BelongsTo<OrganizationMembership, $this>
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(OrganizationMembership::class, 'membership_id');
    }

    /**
     * Assignments that currently grant something: not revoked, not expired.
     *
     * @param  Builder<self>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->whereNull('revoked_at')
            ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }
}
