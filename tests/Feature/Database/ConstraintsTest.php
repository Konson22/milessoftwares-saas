<?php

use App\Models\User;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationSlug;
use App\Modules\Structure\Models\Branch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates an application with one published catalog version and one role.
 *
 * @return array{application_id: string, role_id: string}
 */
function seedApplicationRole(string $code = 'retail'): array
{
    $applicationId = (string) Str::uuid7();
    $versionId = (string) Str::uuid7();
    $roleId = (string) Str::uuid7();

    DB::table('applications')->insert([
        'id' => $applicationId, 'code' => $code, 'name' => ucfirst($code), 'category' => 'operations',
        'kind' => 'standalone', 'status' => 'available',
    ]);
    DB::table('application_catalog_versions')->insert([
        'id' => $versionId, 'application_id' => $applicationId, 'version' => '1.0.0', 'status' => 'published',
    ]);
    DB::table('application_roles')->insert([
        'id' => $roleId, 'application_id' => $applicationId, 'catalog_version_id' => $versionId,
        'key' => 'branch_manager', 'name' => 'Branch Manager',
    ]);

    return ['application_id' => $applicationId, 'role_id' => $roleId];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function insertAssignment(OrganizationMembership $membership, array $role, array $overrides = []): void
{
    DB::table('role_assignments')->insert([
        'id' => (string) Str::uuid7(),
        'organization_id' => $membership->organization_id,
        'membership_id' => $membership->id,
        'application_id' => $role['application_id'],
        'application_role_id' => $role['role_id'],
        'scope_type' => 'organization',
        ...$overrides,
    ]);
}

beforeEach(function () {
    $this->org = Organization::factory()->create();
    $this->membership = OrganizationMembership::factory()->create(['organization_id' => $this->org->id]);
    $this->role = seedApplicationRole();
});

it('rejects users with the same email in different case', function () {
    User::factory()->create(['email' => 'owner@example.com']);
    User::factory()->create(['email' => 'Owner@Example.com']);
})->throws(QueryException::class, 'users_email_lower_unique');

it('rejects invalid organization status', function () {
    DB::table('organizations')->where('id', $this->org->id)->update(['status' => 'deleted']);
})->throws(QueryException::class, 'organizations_status_check');

it('allows only one primary slug per organization', function () {
    $slug = new OrganizationSlug(['slug' => 'secondprimary', 'is_primary' => true]);
    $slug->organization()->associate($this->org)->save();
})->throws(QueryException::class, 'organization_slugs_one_primary');

it('rejects malformed slugs at the database level', function () {
    $slug = new OrganizationSlug(['slug' => 'Bad_Slug', 'is_primary' => false]);
    $slug->organization()->associate($this->org)->save();
})->throws(QueryException::class, 'organization_slugs_format_check');

it('rejects a membership position from another organization', function () {
    $other = Organization::factory()->create();
    $positionId = (string) Str::uuid7();
    DB::table('positions')->insert(['id' => $positionId, 'organization_id' => $other->id, 'title' => 'Cashier', 'code' => 'CSH']);

    DB::table('organization_memberships')->where('id', $this->membership->id)->update(['position_id' => $positionId]);
})->throws(QueryException::class, 'organization_memberships_organization_id_position_id_foreign');

it('accepts an organization-wide role assignment', function () {
    insertAssignment($this->membership, $this->role);

    expect(DB::table('role_assignments')->count())->toBe(1);
});

it('requires a branch for branch-scoped assignments', function () {
    insertAssignment($this->membership, $this->role, ['scope_type' => 'branch']);
})->throws(QueryException::class, 'role_assignments_scope_check');

it('rejects mixed scope columns on one assignment', function () {
    $branch = Branch::factory()->create(['organization_id' => $this->org->id]);

    insertAssignment($this->membership, $this->role, ['scope_type' => 'organization', 'branch_id' => $branch->id]);
})->throws(QueryException::class, 'role_assignments_scope_check');

it('rejects a branch scope pointing at another organization\'s branch', function () {
    $foreignBranch = Branch::factory()->create();

    insertAssignment($this->membership, $this->role, ['scope_type' => 'branch', 'branch_id' => $foreignBranch->id]);
})->throws(QueryException::class, 'role_assignments_organization_id_branch_id_foreign');

it('rejects a role that belongs to a different application', function () {
    $finance = seedApplicationRole('finance');

    insertAssignment($this->membership, ['application_id' => $this->role['application_id'], 'role_id' => $finance['role_id']]);
})->throws(QueryException::class, 'role_assignments_application_id_application_role_id_foreign');

it('keeps separate assignments for the same role in different branches', function () {
    $branchA = Branch::factory()->create(['organization_id' => $this->org->id]);
    $branchB = Branch::factory()->create(['organization_id' => $this->org->id]);

    insertAssignment($this->membership, $this->role, ['scope_type' => 'branch', 'branch_id' => $branchA->id]);
    insertAssignment($this->membership, $this->role, ['scope_type' => 'branch', 'branch_id' => $branchB->id]);

    expect(DB::table('role_assignments')->where('membership_id', $this->membership->id)->count())->toBe(2);
});

it('rejects a duplicate live assignment at the same scope', function () {
    insertAssignment($this->membership, $this->role);
    insertAssignment($this->membership, $this->role);
})->throws(QueryException::class, 'role_assignments_active_unique');

it('allows re-granting after the earlier assignment was revoked', function () {
    insertAssignment($this->membership, $this->role, ['revoked_at' => now()]);
    insertAssignment($this->membership, $this->role);

    expect(DB::table('role_assignments')->count())->toBe(2);
});

it('limits support access grants to 72 hours', function () {
    $staffUser = User::factory()->create();
    $staffId = (string) Str::uuid7();
    DB::table('platform_staff')->insert(['id' => $staffId, 'user_id' => $staffUser->id, 'status' => 'active']);

    DB::table('support_access_grants')->insert([
        'id' => (string) Str::uuid7(), 'platform_staff_id' => $staffId, 'organization_id' => $this->org->id,
        'reason' => 'Investigate ticket', 'starts_at' => now(), 'expires_at' => now()->addDays(7),
    ]);
})->throws(QueryException::class, 'support_access_grants_window_check');
