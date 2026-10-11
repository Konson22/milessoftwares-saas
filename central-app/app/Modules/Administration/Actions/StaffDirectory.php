<?php

namespace App\Modules\Administration\Actions;

use App\Models\User;
use App\Modules\Administration\Models\PlatformRole;
use App\Modules\Administration\Models\PlatformStaff;
use App\Modules\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Staff membership and platform roles. Two rules protect the platform from
 * lock-out and self-escalation: nobody changes their own roles or status, and
 * the last active super admin cannot be demoted or suspended.
 */
final class StaffDirectory
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function add(User $user, ?PlatformStaff $actor): PlatformStaff
    {
        $staff = PlatformStaff::query()->where('user_id', $user->getKey())->first();

        if ($staff === null) {
            $staff = new PlatformStaff;
            $staff->forceFill(['user_id' => $user->getKey(), 'status' => 'active'])->save();
            $this->record('staff.added', $staff, $actor);
        }

        return $staff;
    }

    public function grantRole(PlatformStaff $staff, string $roleKey, ?PlatformStaff $actor): void
    {
        $this->assertNotSelf($staff, $actor);
        $role = PlatformRole::query()->where('key', $roleKey)->firstOrFail();

        if ($staff->roles()->whereKey($role->getKey())->exists()) {
            return;
        }

        $staff->roles()->attach($role->getKey(), ['granted_by_staff_id' => $actor?->getKey(), 'granted_at' => now()]);
        $staff->unsetRelation('roles');
        $this->record('staff.role_granted', $staff, $actor, ['role' => $roleKey]);
    }

    public function revokeRole(PlatformStaff $staff, string $roleKey, PlatformStaff $actor): void
    {
        $this->assertNotSelf($staff, $actor);

        if ($roleKey === 'super_admin' && $this->isLastActiveSuperAdmin($staff)) {
            throw new StaffChangeDenied('The last active super admin cannot be demoted.');
        }

        $role = PlatformRole::query()->where('key', $roleKey)->firstOrFail();
        $staff->roles()->detach($role->getKey());
        $staff->unsetRelation('roles');
        $this->record('staff.role_revoked', $staff, $actor, ['role' => $roleKey]);
    }

    public function setStatus(PlatformStaff $staff, string $status, PlatformStaff $actor): void
    {
        $this->assertNotSelf($staff, $actor);

        if (! in_array($status, ['active', 'suspended', 'removed'], true)) {
            throw new RuntimeException("Unknown staff status [{$status}].");
        }

        if ($status !== 'active' && $this->isLastActiveSuperAdmin($staff)) {
            throw new StaffChangeDenied('The last active super admin cannot be suspended.');
        }

        $staff->forceFill(['status' => $status])->save();
        $this->record('staff.status_changed', $staff, $actor, ['status' => $status]);
    }

    private function assertNotSelf(PlatformStaff $staff, ?PlatformStaff $actor): void
    {
        if ($actor !== null && $actor->is($staff)) {
            throw new StaffChangeDenied('You cannot change your own staff roles or status.');
        }
    }

    private function isLastActiveSuperAdmin(PlatformStaff $staff): bool
    {
        if (! $staff->isActive() || ! $staff->hasRole('super_admin')) {
            return false;
        }

        $activeSuperAdmins = DB::table('platform_staff_roles')
            ->join('platform_roles', 'platform_roles.id', '=', 'platform_staff_roles.platform_role_id')
            ->join('platform_staff', 'platform_staff.id', '=', 'platform_staff_roles.platform_staff_id')
            ->where('platform_roles.key', 'super_admin')
            ->where('platform_staff.status', 'active')
            ->count();

        return $activeSuperAdmins <= 1;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function record(string $action, PlatformStaff $staff, ?PlatformStaff $actor, array $context = []): void
    {
        $this->audit->record(
            $action,
            $staff,
            ['staff_user_id' => $staff->user_id, ...$context],
            actor: $actor?->user,
            actorType: 'staff',
        );
    }
}
