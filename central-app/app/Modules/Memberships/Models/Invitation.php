<?php

namespace App\Modules\Memberships\Models;

use App\Modules\Memberships\Support\ProposedAccess;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An invitation to join one organization. Only the token's hash is stored;
 * the plain token exists in the invitation email alone.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $email
 * @property string $token_hash
 * @property string $invited_by_membership_id
 * @property array{roles?: list<ProposedRole>, role_id?: string, scope?: array{scope_type: string, branch_id?: string|null, department_id?: string|null}, acknowledge_sod?: bool}|null $proposed_access roles, or one role_id and scope in invitations sent before several roles could be proposed
 * @property string $status pending|accepted|revoked|expired
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property string|null $accepted_by_user_id
 * @property Carbon|null $revoked_at
 * @property-read OrganizationMembership $inviter
 *
 * @phpstan-import-type ProposedRole from ProposedAccess
 */
class Invitation extends Model
{
    use BelongsToOrganization, HasUuids;

    protected function casts(): array
    {
        return [
            'proposed_access' => 'array',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<OrganizationMembership, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(OrganizationMembership::class, 'invited_by_membership_id');
    }

    /**
     * The roles proposed for the invitee, in either stored shape.
     *
     * @return list<ProposedRole>
     */
    public function proposedRoles(): array
    {
        $proposed = $this->proposed_access;

        if ($proposed === null) {
            return [];
        }

        if (isset($proposed['role_id'], $proposed['scope'])) {
            return [['role_id' => $proposed['role_id'], 'scope' => $proposed['scope']]];
        }

        return $proposed['roles'] ?? [];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * The status as of now: a pending invitation past its expiry is expired
     * even before the cleanup job has marked it.
     */
    public function effectiveStatus(): string
    {
        return $this->status === 'pending' && $this->expires_at->isPast() ? 'expired' : $this->status;
    }
}
