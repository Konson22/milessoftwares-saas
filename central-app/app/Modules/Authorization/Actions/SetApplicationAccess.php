<?php

namespace App\Modules\Authorization\Actions;

use App\Modules\Applications\Models\Application;
use App\Modules\Applications\Models\ApplicationPermission;
use App\Modules\Audit\AuditLogger;
use App\Modules\Authorization\Support\AccessResolver;
use App\Modules\Authorization\Support\Scope;
use App\Modules\Entitlements\Models\Entitlement;
use App\Modules\Integration\Support\IntegrationEvents;
use App\Modules\Memberships\Models\OrganizationMembership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Whether a member may sign in to one application: active, disabled or
 * suspended. Separate from their roles (what they may do there) and from
 * their membership (the whole workspace): stopping someone in one
 * application leaves the others alone.
 *
 * Who may change it: the owner; or someone who manages access for the
 * whole organization and, when the application's catalog has a
 * "<code>.access.manage" permission, holds that too across the
 * organization. Nobody changes their own access or the owner's, and
 * nobody stops someone holding a more sensitive role there than they do
 * (a manager cannot lock out the administrator above them).
 *
 * Products learn at once: access.changed is sent and cached answers expire.
 */
final class SetApplicationAccess
{
    public const STATES = ['active', 'disabled', 'suspended'];

    public function __construct(
        private readonly AccessResolver $access,
        private readonly AuditLogger $audit,
        private readonly IntegrationEvents $events,
    ) {}

    /**
     * @throws AssignmentDenied
     */
    public function handle(OrganizationMembership $actor, OrganizationMembership $member, Application $application, string $status, ?string $reason = null): void
    {
        if (! in_array($status, self::STATES, true)) {
            throw new AssignmentDenied('invalid_state', 'Choose enabled, disabled or suspended.');
        }

        if ($actor->organization_id !== $member->organization_id) {
            throw new AssignmentDenied('cross_tenant', 'That member is not part of this organization.');
        }

        if ($actor->is($member)) {
            throw new AssignmentDenied('self', 'You cannot change your own access.');
        }

        if ($this->access->isOwner($member)) {
            throw new AssignmentDenied('owner', 'The owner always has access to every application.');
        }

        if (! $member->isActive()) {
            throw new AssignmentDenied('grantee_inactive', 'Only active members have access to change.');
        }

        $entitled = Entitlement::query()->current()->where('application_id', $application->id)->whereNull('feature_key')->exists();
        if (! $entitled) {
            throw new AssignmentDenied('not_entitled', "Your organization is not subscribed to {$application->name}.");
        }

        if (! $this->access->isOwner($actor)) {
            $this->assertMayManage($actor, $member, $application);
        }

        $current = DB::table('application_access_grants')
            ->where('organization_id', $member->organization_id)
            ->where('membership_id', $member->getKey())
            ->where('application_id', $application->id)
            ->first();
        $from = $current->status ?? null;

        if ($from === $status) {
            return;
        }

        DB::transaction(function () use ($current, $actor, $member, $application, $status): void {
            $values = [
                'status' => $status,
                'granted_by_membership_id' => $actor->getKey(),
                'revoked_at' => $status === 'active' ? null : now(),
                'updated_at' => now(),
            ];

            if ($current !== null) {
                DB::table('application_access_grants')->where('id', $current->id)->update($values);

                return;
            }

            DB::table('application_access_grants')->insert([
                'id' => (string) Str::uuid7(),
                'organization_id' => $member->organization_id,
                'membership_id' => $member->getKey(),
                'application_id' => $application->id,
                'created_at' => now(),
                ...$values,
            ]);
        });

        $this->events->accessChanged($member->organization_id, $application->id, $member->id, $member->user_id);

        $this->audit->record('application_access.changed', $member, [
            'membership_id' => $member->getKey(),
            'application_id' => $application->id,
            'application' => $application->code,
            'from' => $from,
            'to' => $status,
            'reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : null,
            'changed_by_membership_id' => $actor->getKey(),
        ], organizationId: $member->organization_id);
    }

    /**
     * @throws AssignmentDenied
     */
    private function assertMayManage(OrganizationMembership $actor, OrganizationMembership $member, Application $application): void
    {
        $organization = Scope::organization();

        if (! $this->access->holds($actor, 'platform.access.manage', $organization)) {
            throw new AssignmentDenied('no_access_manage', 'Only someone who manages access for the whole organization can change who signs in.');
        }

        $gate = "{$application->code}.access.manage";
        $catalogHasGate = ApplicationPermission::query()
            ->where('catalog_version_id', $application->current_catalog_version_id)
            ->where('key', $gate)
            ->exists();

        if ($catalogHasGate && ! $this->access->holds($actor, $gate, $organization)) {
            throw new AssignmentDenied('no_application_admin', "Only {$application->name}'s administrators can change who signs in to it.");
        }

        if ($this->access->maxSensitivity($member, $application->id) > $this->access->maxSensitivity($actor, $application->id)) {
            throw new AssignmentDenied('sensitivity_exceeded', "This member holds a more sensitive role in {$application->name} than you do.");
        }
    }
}
