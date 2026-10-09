<?php

namespace App\Modules\Authorization\Actions;

use App\Modules\Applications\Enums\ApplicationKind;
use App\Modules\Applications\Models\ApplicationRole;
use App\Modules\Applications\Models\SodConstraint;
use App\Modules\Audit\AuditLogger;
use App\Modules\Authorization\Enums\ScopeType;
use App\Modules\Authorization\Models\RoleAssignment;
use App\Modules\Authorization\Support\AccessResolver;
use App\Modules\Authorization\Support\Scope;
use App\Modules\Entitlements\Models\Entitlement;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Structure\Models\Branch;
use App\Modules\Structure\Models\Department;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Grants a role at one scope, enforcing the delegation rules:
 *
 *  (a) the grantor holds platform.access.manage at a scope containing the target;
 *  (b) the role is delegable and no more sensitive than the grantor's highest
 *      role in that application;
 *  (c) the organization is entitled to the application;
 *  (d) nobody grants themselves a sensitive role.
 *
 * Owners are bounded only by (c). Separation-of-duties pairs from the
 * catalog are checked against the grantee's other live assignments at
 * overlapping scopes: `block` pairs refuse, `warn` pairs need acknowledgement.
 *
 * Runs inside the tenant context; every id is resolved through tenant-scoped
 * queries, so ids from another organization are simply not found.
 */
final class AssignRole
{
    /**
     * Roles above this sensitivity cannot be self-assigned by non-owners.
     */
    private const SELF_ASSIGNABLE_SENSITIVITY = 1;

    public function __construct(
        private readonly AccessResolver $access,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws AssignmentDenied
     * @throws SeparationOfDutiesWarning
     */
    public function handle(
        OrganizationMembership $grantor,
        OrganizationMembership $grantee,
        ApplicationRole $role,
        Scope $scope,
        ?CarbonInterface $expiresAt = null,
        bool $acknowledgeSeparationOfDuties = false,
    ): RoleAssignment {
        if ($grantor->organization_id !== $grantee->organization_id) {
            throw new AssignmentDenied('cross_tenant', 'That member is not part of this organization.');
        }

        $this->assertAssignable($grantee, $role, $scope, $expiresAt);
        $this->assertEntitled($role);

        if (! $this->access->isOwner($grantor)) {
            $this->assertDelegationAllowed($grantor, $grantee, $role, $scope);
        }

        $this->assertSeparationOfDuties($grantee, $role, $scope, $acknowledgeSeparationOfDuties);

        try {
            $assignment = DB::transaction(function () use ($grantor, $grantee, $role, $scope, $expiresAt): RoleAssignment {
                $this->ensureApplicationAccess($grantor, $grantee, $role->application_id);

                $assignment = new RoleAssignment;
                $assignment->forceFill([
                    'membership_id' => $grantee->getKey(),
                    'application_id' => $role->application_id,
                    'application_role_id' => $role->getKey(),
                    ...$scope->attributes(),
                    'granted_by_membership_id' => $grantor->getKey(),
                    'expires_at' => $expiresAt,
                ])->save();

                return $assignment;
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23505' && str_contains($e->getMessage(), 'role_assignments_active_unique')) {
                throw new AssignmentDenied('duplicate', 'This member already has that role at that scope.');
            }

            throw $e;
        }

        $this->audit->record('role.assigned', $assignment, [
            'membership_id' => $grantee->getKey(),
            'role' => $role->key,
            'application_id' => $role->application_id,
            'scope' => $scope->attributes(),
            'expires_at' => $expiresAt?->toIso8601String(),
            'granted_by_membership_id' => $grantor->getKey(),
            'sod_acknowledged' => $acknowledgeSeparationOfDuties,
        ]);

        return $assignment;
    }

    private function assertAssignable(OrganizationMembership $grantee, ApplicationRole $role, Scope $scope, ?CarbonInterface $expiresAt): void
    {
        if (! $grantee->isActive()) {
            throw new AssignmentDenied('grantee_inactive', 'Roles can only be given to active members.');
        }

        if ($role->application->current_catalog_version_id !== $role->catalog_version_id) {
            throw new AssignmentDenied('role_retired', 'This role belongs to an older catalog version.');
        }

        if (! $role->allowsScope($scope->type)) {
            throw new AssignmentDenied('scope_not_allowed', "{$role->name} cannot be assigned at {$scope->type->value} scope.");
        }

        $exists = match ($scope->type) {
            ScopeType::Branch => Branch::query()->whereKey($scope->branchId)->where('status', 'active')->exists(),
            ScopeType::Department => Department::query()->whereKey($scope->departmentId)->where('status', 'active')->exists(),
            default => true,
        };

        if (! $exists) {
            throw new AssignmentDenied('scope_not_found', 'That branch or department does not exist.');
        }

        if ($expiresAt !== null && $expiresAt->isPast()) {
            throw new AssignmentDenied('expiry_in_past', 'The expiry must be in the future.');
        }
    }

    private function assertEntitled(ApplicationRole $role): void
    {
        if ($role->application->kind === ApplicationKind::Platform) {
            return;
        }

        $entitled = Entitlement::query()
            ->current()
            ->where('application_id', $role->application_id)
            ->whereNull('feature_key')
            ->exists();

        if (! $entitled) {
            throw new AssignmentDenied('not_entitled', "Your organization is not subscribed to {$role->application->name}.");
        }
    }

    private function assertDelegationAllowed(OrganizationMembership $grantor, OrganizationMembership $grantee, ApplicationRole $role, Scope $scope): void
    {
        if (! $this->access->holds($grantor, 'platform.access.manage', $scope)) {
            throw new AssignmentDenied('no_access_manage', 'You cannot manage access at that scope.');
        }

        if (! $role->is_delegable) {
            throw new AssignmentDenied('not_delegable', "{$role->name} can only be assigned by the organization owner.");
        }

        if ($role->sensitivity > $this->access->maxSensitivity($grantor, $role->application_id)) {
            throw new AssignmentDenied('sensitivity_exceeded', "{$role->name} is more sensitive than any role you hold in {$role->application->name}.");
        }

        if ($grantor->is($grantee) && $role->sensitivity > self::SELF_ASSIGNABLE_SENSITIVITY) {
            throw new AssignmentDenied('self_elevation', 'You cannot give yourself a sensitive role.');
        }
    }

    /**
     * @throws AssignmentDenied
     * @throws SeparationOfDutiesWarning
     */
    private function assertSeparationOfDuties(OrganizationMembership $grantee, ApplicationRole $role, Scope $scope, bool $acknowledged): void
    {
        $constraints = SodConstraint::query()
            ->where('catalog_version_id', $role->application->current_catalog_version_id)
            ->with('permissionA:id,key', 'permissionB:id,key')
            ->get();

        if ($constraints->isEmpty()) {
            return;
        }

        $new = $role->permissions()->pluck('key')->all();

        // Permission keys the grantee holds at scopes overlapping the target.
        $held = $this->access->liveAssignments($grantee, $role->application_id)
            ->filter(fn (RoleAssignment $assignment) => $this->access->tree()->overlaps(Scope::of($assignment), $scope))
            ->flatMap(fn (RoleAssignment $assignment) => $assignment->role->permissions->pluck('key'))
            ->all();

        $combined = array_unique([...$new, ...$held]);
        $warnings = [];

        foreach ($constraints as $constraint) {
            [$a, $b] = [$constraint->permissionA->key, $constraint->permissionB->key];

            $conflict = in_array($a, $combined, true) && in_array($b, $combined, true)
                && (in_array($a, $new, true) || in_array($b, $new, true));

            if (! $conflict) {
                continue;
            }

            $description = $constraint->description ?? "{$a} and {$b} should not be held together.";

            if ($constraint->blocks()) {
                throw new AssignmentDenied('sod_blocked', $description);
            }

            $warnings[] = $description;
        }

        if ($warnings !== [] && ! $acknowledged) {
            throw new SeparationOfDutiesWarning($warnings);
        }
    }

    private function ensureApplicationAccess(OrganizationMembership $grantor, OrganizationMembership $grantee, string $applicationId): void
    {
        $exists = DB::table('application_access_grants')
            ->where('organization_id', $grantee->organization_id)
            ->where('membership_id', $grantee->getKey())
            ->where('application_id', $applicationId)
            ->exists();

        if ($exists) {
            DB::table('application_access_grants')
                ->where('membership_id', $grantee->getKey())
                ->where('application_id', $applicationId)
                ->update(['status' => 'active', 'revoked_at' => null, 'updated_at' => now()]);

            return;
        }

        DB::table('application_access_grants')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $grantee->organization_id,
            'membership_id' => $grantee->getKey(),
            'application_id' => $applicationId,
            'status' => 'active',
            'granted_by_membership_id' => $grantor->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
