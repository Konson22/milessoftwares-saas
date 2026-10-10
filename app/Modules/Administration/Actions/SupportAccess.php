<?php

namespace App\Modules\Administration\Actions;

use App\Modules\Administration\Enums\PlatformPermission;
use App\Modules\Administration\Models\PlatformStaff;
use App\Modules\Administration\Models\SupportAccessGrant;
use App\Modules\Administration\Notifications\SupportAccessApproved;
use App\Modules\Audit\AuditLogger;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Organizations\Models\Organization;

/**
 * The only way staff reach customer data. A grant is requested with a reason,
 * approved by a different staff member, limited to 72 hours, revocable, and
 * every step and every data view is audited against the organization. The
 * organization's owner is told when access is approved.
 */
final class SupportAccess
{
    public const MAX_HOURS = 72;

    public function __construct(private readonly AuditLogger $audit) {}

    public function request(PlatformStaff $staff, Organization $organization, string $reason, ?string $ticket, string $scope, int $hours): SupportAccessGrant
    {
        if ($hours < 1 || $hours > self::MAX_HOURS) {
            throw new SupportAccessDenied('Support access lasts between 1 and '.self::MAX_HOURS.' hours.');
        }

        $grant = new SupportAccessGrant;
        $grant->forceFill([
            'platform_staff_id' => $staff->getKey(),
            'organization_id' => $organization->getKey(),
            'reason' => $reason,
            'ticket_reference' => $ticket,
            'scope' => $scope,
            'starts_at' => now(),
            'expires_at' => now()->addHours($hours),
        ])->save();

        $this->record('support.requested', $grant, $staff, ['hours' => $hours, 'scope' => $scope, 'ticket' => $ticket]);

        return $grant;
    }

    public function approve(PlatformStaff $approver, SupportAccessGrant $grant): void
    {
        if (! $approver->hasPermission(PlatformPermission::SupportApprove)) {
            throw new SupportAccessDenied('You cannot approve support access.');
        }

        if ($grant->platform_staff_id === $approver->getKey()) {
            throw new SupportAccessDenied('Support access must be approved by someone else.');
        }

        if ($grant->status() !== 'pending') {
            throw new SupportAccessDenied('Only pending requests can be approved.');
        }

        // Access starts at approval; the requested end time stays, so the
        // window can only shrink.
        $grant->forceFill(['approved_by_staff_id' => $approver->getKey(), 'starts_at' => now()])->save();

        $this->record('support.approved', $grant, $approver);
        $this->notifyOwner($grant);
    }

    public function revoke(PlatformStaff $staff, SupportAccessGrant $grant): void
    {
        $mayRevoke = $grant->platform_staff_id === $staff->getKey() || $staff->hasPermission(PlatformPermission::SupportApprove);

        if (! $mayRevoke) {
            throw new SupportAccessDenied('Only the requester or a support manager can revoke this access.');
        }

        if ($grant->revoked_at !== null) {
            return;
        }

        $grant->forceFill(['revoked_at' => now()])->save();
        $this->record('support.revoked', $grant, $staff);
    }

    /**
     * The staff member's grant for this organization that is in effect now.
     */
    public function inEffect(PlatformStaff $staff, Organization $organization): ?SupportAccessGrant
    {
        return SupportAccessGrant::query()
            ->inEffect()
            ->where('platform_staff_id', $staff->getKey())
            ->where('organization_id', $organization->getKey())
            ->latest('expires_at')
            ->first();
    }

    /**
     * @throws SupportAccessDenied
     */
    public function requireInEffect(PlatformStaff $staff, Organization $organization): SupportAccessGrant
    {
        return $this->inEffect($staff, $organization)
            ?? throw new SupportAccessDenied('You need approved, unexpired support access to view this organization\'s data.');
    }

    public function recordDataView(SupportAccessGrant $grant, PlatformStaff $staff, string $what): void
    {
        $this->record('support.data_viewed', $grant, $staff, ['viewed' => $what]);
    }

    private function notifyOwner(SupportAccessGrant $grant): void
    {
        $ownerId = $grant->organization->owner_membership_id;

        if ($ownerId === null) {
            return;
        }

        $owner = OrganizationMembership::acrossTenants()
            ->where('organization_id', $grant->organization_id)
            ->whereKey($ownerId)
            ->with('user')
            ->first();

        $owner?->user->notify(new SupportAccessApproved(
            $grant->organization->display_name,
            $grant->reason,
            $grant->scope,
            $grant->expires_at,
        ));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function record(string $action, SupportAccessGrant $grant, PlatformStaff $actor, array $context = []): void
    {
        $this->audit->record(
            $action,
            $grant,
            ['grant_id' => $grant->getKey(), 'requested_by_staff_id' => $grant->platform_staff_id, ...$context],
            actor: $actor->user,
            organizationId: $grant->organization_id,
            actorType: 'staff',
        );
    }
}
