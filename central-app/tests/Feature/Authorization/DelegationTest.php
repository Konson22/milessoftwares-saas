<?php

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Authorization\Actions\AssignmentDenied;
use App\Modules\Authorization\Actions\RevokeRole;
use App\Modules\Authorization\Actions\SeparationOfDutiesWarning;
use App\Modules\Authorization\Models\RoleAssignment;
use App\Modules\Authorization\Support\Scope;
use App\Modules\Entitlements\Models\Entitlement;
use App\Modules\Memberships\Enums\MembershipStatus;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Structure\Models\Branch;
use App\Support\Tenancy\TenantContext;
use Tests\Support\AuthorizationFixtures;

uses(AuthorizationFixtures::class);

beforeEach(fn () => $this->prepareAuthorizationFixtures());

/**
 * @param  Closure(): mixed  $attempt
 */
function expectDenied(Closure $attempt, string $reason): void
{
    try {
        $attempt();
    } catch (AssignmentDenied $e) {
        expect($e->reason)->toBe($reason);

        return;
    }

    test()->fail("Expected the assignment to be denied with [{$reason}].");
}

describe('owner', function () {
    it('can assign any role, including non-delegable ones', function () {
        $assignment = $this->grant($this->member(), 'retail', 'retail_owner');

        expect($assignment->exists)->toBeTrue()
            ->and(AuditLog::query()->where('action', 'role.assigned')->count())->toBe(1);
    });

    it('is still bound by entitlements', function () {
        Entitlement::query()->update(['revoked_at' => now()]);

        expectDenied(fn () => $this->grant($this->member(), 'retail', 'cashier', Scope::branch($this->juba->id)), 'not_entitled');
    });

    it('cannot assign a role at a scope the catalog does not allow', function () {
        expectDenied(fn () => $this->grant($this->member(), 'retail', 'cashier', Scope::organization()), 'scope_not_allowed');
    });
});

describe('members without access management', function () {
    it('cannot assign any role', function () {
        $plain = $this->member();
        $this->grant($plain, 'retail', 'auditor');

        expectDenied(fn () => $this->assignAs($plain, $this->member(), 'retail', 'auditor'), 'no_access_manage');
    });

    it('cannot assign roles even with high roles in the application', function () {
        $manager = $this->member();
        $this->grant($manager, 'retail', 'retail_owner');

        expectDenied(fn () => $this->assignAs($manager, $this->member(), 'retail', 'auditor'), 'no_access_manage');
    });
});

describe('access administrators', function () {
    beforeEach(function () {
        $this->admin = $this->member();
        $this->grant($this->admin, 'platform', 'access_administrator');
    });

    it('can delegate roles up to their own sensitivity in that application', function () {
        $this->grant($this->admin, 'retail', 'payments_clerk');

        $assignment = $this->assignAs($this->admin, $this->member(), 'retail', 'cashier', Scope::branch($this->juba->id));

        expect($assignment->granted_by_membership_id)->toBe($this->admin->id);
    });

    it('cannot delegate a role more sensitive than any they hold there', function () {
        $this->grant($this->admin, 'retail', 'payments_clerk');

        expectDenied(fn () => $this->assignAs($this->admin, $this->member(), 'retail', 'payments_approver'), 'sensitivity_exceeded');
    });

    it('cannot delegate application roles while holding none in that application', function () {
        expectDenied(fn () => $this->assignAs($this->admin, $this->member(), 'retail', 'cashier', Scope::branch($this->juba->id)), 'sensitivity_exceeded');
    });

    it('cannot delegate non-delegable roles', function () {
        expectDenied(fn () => $this->assignAs($this->admin, $this->member(), 'retail', 'retail_owner'), 'not_delegable');
    });

    it('cannot give themselves a sensitive role', function () {
        $this->grant($this->admin, 'retail', 'payments_approver');

        expectDenied(fn () => $this->assignAs($this->admin, $this->admin, 'retail', 'payments_clerk'), 'self_elevation');
    });

    it('cannot make themselves a second access administrator elsewhere', function () {
        expectDenied(fn () => $this->assignAs($this->admin, $this->admin, 'platform', 'access_administrator', Scope::branch($this->juba->id)), 'self_elevation');
    });

    it('may give themselves a low-sensitivity role', function () {
        $assignment = $this->assignAs($this->admin, $this->admin, 'platform', 'member_viewer');

        expect($assignment->exists)->toBeTrue();
    });

    it('cannot assign to a suspended member', function () {
        $suspended = $this->member();
        $suspended->forceFill(['status' => MembershipStatus::Suspended])->save();

        expectDenied(fn () => $this->assignAs($this->admin, $suspended, 'platform', 'member_viewer'), 'grantee_inactive');
    });

    it('rejects duplicate assignments at the same scope', function () {
        $grantee = $this->member();
        $this->assignAs($this->admin, $grantee, 'platform', 'member_viewer');

        expectDenied(fn () => $this->assignAs($this->admin, $grantee, 'platform', 'member_viewer'), 'duplicate');
    });

    it('loses delegation rights once their assignment is revoked or expires', function () {
        $assignment = RoleAssignment::query()->where('membership_id', $this->admin->id)->sole();
        $assignment->forceFill(['expires_at' => now()->subMinute()])->save();

        expectDenied(fn () => $this->assignAs($this->admin, $this->member(), 'platform', 'member_viewer'), 'no_access_manage');
    });
});

describe('scoped access administrators', function () {
    beforeEach(function () {
        $this->branchAdmin = $this->member();
        $this->grant($this->branchAdmin, 'platform', 'access_administrator', Scope::branch($this->juba->id));
        $this->grant($this->branchAdmin, 'retail', 'store_manager', Scope::branch($this->juba->id));
    });

    it('can assign within their branch and its departments', function () {
        $this->assignAs($this->branchAdmin, $this->member(), 'retail', 'cashier', Scope::branch($this->juba->id));
        $this->assignAs($this->branchAdmin, $this->member(), 'retail', 'auditor', Scope::department($this->jubaSalesFloor->id));

        expect(RoleAssignment::query()->where('granted_by_membership_id', $this->branchAdmin->id)->count())->toBe(2);
    });

    it('cannot assign in another branch', function () {
        expectDenied(fn () => $this->assignAs($this->branchAdmin, $this->member(), 'retail', 'cashier', Scope::branch($this->wau->id)), 'no_access_manage');
    });

    it('cannot widen a role to the whole organization', function () {
        expectDenied(fn () => $this->assignAs($this->branchAdmin, $this->member(), 'retail', 'auditor', Scope::organization()), 'no_access_manage');
    });

    it('cannot assign in an organization-wide department outside the branch', function () {
        expectDenied(fn () => $this->assignAs($this->branchAdmin, $this->member(), 'retail', 'auditor', Scope::department($this->headOffice->id)), 'no_access_manage');
    });

    it('cannot revoke assignments outside their branch', function () {
        $wauCashier = $this->grant($this->member(), 'retail', 'cashier', Scope::branch($this->wau->id));

        expectDenied(fn () => app(RevokeRole::class)->handle($this->branchAdmin, $wauCashier), 'no_access_manage');
        expect($wauCashier->refresh()->revoked_at)->toBeNull();
    });

    it('can revoke assignments inside their branch', function () {
        $cashier = $this->grant($this->member(), 'retail', 'cashier', Scope::branch($this->juba->id));

        app(RevokeRole::class)->handle($this->branchAdmin, $cashier);

        expect($cashier->refresh()->revoked_at)->not->toBeNull()
            ->and($cashier->revoked_by_membership_id)->toBe($this->branchAdmin->id);
    });

    it('cannot revoke roles more sensitive than their own', function () {
        $approver = $this->grant($this->member(), 'retail', 'retail_owner');
        $orgAdmin = $this->member();
        $this->grant($orgAdmin, 'platform', 'access_administrator');
        $this->grant($orgAdmin, 'retail', 'payments_clerk');

        expectDenied(fn () => app(RevokeRole::class)->handle($orgAdmin, $approver), 'sensitivity_exceeded');
    });
});

describe('scope targets', function () {
    it('cannot target a branch of another organization', function () {
        $other = Organization::factory()->create();
        $foreignBranch = app(TenantContext::class)->run($other, fn () => Branch::query()->create(['name' => 'Foreign', 'code' => 'FOR']));

        expectDenied(fn () => $this->grant($this->member(), 'retail', 'cashier', Scope::branch($foreignBranch->id)), 'scope_not_found');
    });

    it('cannot target an archived branch', function () {
        $this->wau->forceFill(['status' => 'archived'])->save();

        expectDenied(fn () => $this->grant($this->member(), 'retail', 'cashier', Scope::branch($this->wau->id)), 'scope_not_found');
    });
});

describe('separation of duties', function () {
    it('blocks combining payment creation and approval at overlapping scopes', function () {
        $clerk = $this->member();
        $this->grant($clerk, 'retail', 'payments_clerk', Scope::branch($this->juba->id));

        expectDenied(fn () => $this->grant($clerk, 'retail', 'payments_approver'), 'sod_blocked');
        expectDenied(fn () => $this->grant($clerk, 'retail', 'payments_approver', Scope::branch($this->juba->id)), 'sod_blocked');
    });

    it('allows the pair in branches that do not overlap', function () {
        $clerk = $this->member();
        $this->grant($clerk, 'retail', 'payments_clerk', Scope::branch($this->juba->id));

        $assignment = $this->grant($clerk, 'retail', 'payments_approver', Scope::branch($this->wau->id));

        expect($assignment->exists)->toBeTrue();
    });

    it('ignores revoked assignments', function () {
        $clerk = $this->member();
        $clerkRole = $this->grant($clerk, 'retail', 'payments_clerk');
        app(RevokeRole::class)->handle($this->owner, $clerkRole);

        expect($this->grant($clerk, 'retail', 'payments_approver')->exists)->toBeTrue();
    });

    it('asks for acknowledgement on warning pairs', function () {
        $cashier = $this->member();
        $this->grant($cashier, 'retail', 'cashier', Scope::branch($this->juba->id));

        try {
            $this->grant($cashier, 'retail', 'payments_approver', Scope::branch($this->juba->id));
            $this->fail('Expected a separation-of-duties warning.');
        } catch (SeparationOfDutiesWarning $warning) {
            expect($warning->conflicts)->toBe(['Cashiers approving payments needs a second look.']);
        }

        $assignment = $this->assignAs($this->owner, $cashier, 'retail', 'payments_approver', Scope::branch($this->juba->id), acknowledge: true);

        expect($assignment->exists)->toBeTrue()
            ->and(AuditLog::query()->where('action', 'role.assigned')->latest('sequence')->first()->context['sod_acknowledged'])->toBeTrue();
    });
});

it('records who assigned and who revoked in the audit log', function () {
    $assignment = $this->grant($this->member(), 'retail', 'auditor');
    app(RevokeRole::class)->handle($this->owner, $assignment);

    expect(AuditLog::query()->pluck('action')->all())->toBe(['role.assigned', 'role.revoked']);
});
