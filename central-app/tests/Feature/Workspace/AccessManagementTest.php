<?php

use App\Modules\Authorization\Models\RoleAssignment;
use App\Modules\Authorization\Support\Scope;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Organizations\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Support\AuthorizationFixtures;

uses(AuthorizationFixtures::class);

beforeEach(function () {
    $this->prepareAuthorizationFixtures();
    $this->slug = $this->organization->primarySlug->slug;

    $this->branchAdmin = $this->member();
    $this->grant($this->branchAdmin, 'platform', 'access_administrator', Scope::branch($this->juba->id));
    $this->grant($this->branchAdmin, 'retail', 'store_manager', Scope::branch($this->juba->id));

    $this->grantee = $this->member();
    $this->cashierRole = $this->role('retail', 'cashier');
    $this->ownerRole = $this->role('retail', 'retail_owner');

    $other = Organization::factory()->create();
    $this->foreignAssignment = app(TenantContext::class)->run($other, function () use ($other) {
        $this->foreignMembership = OrganizationMembership::factory()->create(['organization_id' => $other->id]);

        $assignment = new RoleAssignment;
        $assignment->forceFill([
            'organization_id' => $other->id,
            'membership_id' => $this->foreignMembership->id,
            'application_id' => $this->role('platform', 'member_viewer')->application_id,
            'application_role_id' => $this->role('platform', 'member_viewer')->id,
            'scope_type' => 'organization',
        ])->save();

        return $assignment;
    });

    app(TenantContext::class)->clear();
});

function workspacePath(string $path): string
{
    return 'http://'.test()->slug.'.'.config('platform.root_domain').$path;
}

function assign(array $input): TestResponse
{
    return test()->post(workspacePath('/manage/access'), $input);
}

it('shows the access page to branch access administrators', function () {
    $this->actingAs($this->branchAdmin->user)
        ->get(workspacePath('/manage/access'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('central/workspace/manage/access')
            ->where('abilities.manageAccess', true)
            ->where('abilities.manageStructure', false)
            ->where('roles', fn ($roles) => collect($roles)->pluck('application')->unique()->sort()->values()->all() === ['Miles Softwares platform', 'Retail']));
});

it('forbids the access page to members without access management', function () {
    $this->actingAs($this->grantee->user)->get(workspacePath('/manage/access'))->assertForbidden();
});

it('lets a branch administrator assign within the branch', function () {
    $this->actingAs($this->branchAdmin->user);

    assign([
        'membership_id' => $this->grantee->id,
        'role_id' => $this->cashierRole->id,
        'scope_type' => 'branch',
        'branch_id' => $this->juba->id,
    ])->assertSessionHasNoErrors();

    expect(RoleAssignment::acrossTenants()->where('membership_id', $this->grantee->id)->count())->toBe(1);
});

it('reports delegation refusals as form errors', function () {
    $this->actingAs($this->branchAdmin->user);

    assign([
        'membership_id' => $this->grantee->id,
        'role_id' => $this->cashierRole->id,
        'scope_type' => 'branch',
        'branch_id' => $this->wau->id,
    ])->assertSessionHasErrors(['role_id' => 'You cannot manage access at that scope.']);

    assign([
        'membership_id' => $this->grantee->id,
        'role_id' => $this->ownerRole->id,
        'scope_type' => 'organization',
    ])->assertSessionHasErrors('role_id');

    expect(RoleAssignment::acrossTenants()->where('membership_id', $this->grantee->id)->count())->toBe(0);
});

it('cannot assign roles to a member of another organization', function () {
    $this->actingAs($this->owner->user);

    assign([
        'membership_id' => $this->foreignMembership->id,
        'role_id' => $this->cashierRole->id,
        'scope_type' => 'branch',
        'branch_id' => $this->juba->id,
    ])->assertSessionHasErrors('membership_id');

    expect(RoleAssignment::acrossTenants()->where('membership_id', $this->foreignMembership->id)->count())->toBe(1);
});

it('returns 404 when revoking another organization\'s assignment', function () {
    $this->actingAs($this->owner->user)
        ->delete(workspacePath('/manage/access/'.$this->foreignAssignment->id))
        ->assertNotFound();

    expect(RoleAssignment::acrossTenants()->find($this->foreignAssignment->id)->revoked_at)->toBeNull();
});

it('asks for confirmation on separation-of-duties warnings', function () {
    app(TenantContext::class)->run($this->organization, fn () => $this->grant($this->grantee, 'retail', 'cashier', Scope::branch($this->juba->id)));
    $approver = $this->role('retail', 'payments_approver');
    $this->actingAs($this->owner->user);

    $input = ['membership_id' => $this->grantee->id, 'role_id' => $approver->id, 'scope_type' => 'branch', 'branch_id' => $this->juba->id];

    assign($input)->assertSessionHasErrors('acknowledge_sod');
    assign([...$input, 'acknowledge_sod' => '1'])->assertSessionHasNoErrors();
});

it('blocks separation-of-duties conflicts outright', function () {
    app(TenantContext::class)->run($this->organization, fn () => $this->grant($this->grantee, 'retail', 'payments_clerk'));
    $this->actingAs($this->owner->user);

    assign([
        'membership_id' => $this->grantee->id,
        'role_id' => $this->role('retail', 'payments_approver')->id,
        'scope_type' => 'organization',
        'acknowledge_sod' => '1',
    ])->assertSessionHasErrors(['role_id' => 'The person who creates a payment must not approve it.']);
});

it('revokes an assignment inside the administrator\'s scope', function () {
    $assignment = app(TenantContext::class)->run($this->organization, fn () => $this->grant($this->grantee, 'retail', 'cashier', Scope::branch($this->juba->id)));

    $this->actingAs($this->branchAdmin->user)
        ->delete(workspacePath('/manage/access/'.$assignment->id))
        ->assertRedirect();

    expect($assignment->refresh()->revoked_at)->not->toBeNull();
});

it('forbids revoking outside the administrator\'s scope', function () {
    $assignment = app(TenantContext::class)->run($this->organization, fn () => $this->grant($this->grantee, 'retail', 'cashier', Scope::branch($this->wau->id)));

    $this->actingAs($this->branchAdmin->user)
        ->delete(workspacePath('/manage/access/'.$assignment->id))
        ->assertForbidden();

    expect($assignment->refresh()->revoked_at)->toBeNull();
});

it('assigns several roles to one member at once', function () {
    $this->actingAs($this->owner->user);

    assign([
        'membership_id' => $this->grantee->id,
        'role_ids' => [$this->cashierRole->id, $this->role('retail', 'auditor')->id],
        'scope_type' => 'branch',
        'branch_id' => $this->juba->id,
    ])->assertSessionHasNoErrors();

    expect(RoleAssignment::acrossTenants()->where('membership_id', $this->grantee->id)->with('role')->get()->pluck('role.key')->sort()->values()->all())
        ->toBe(['auditor', 'cashier']);
});

it('checks separation of duties across the roles given together, all or none', function () {
    $this->actingAs($this->owner->user);
    $input = [
        'membership_id' => $this->grantee->id,
        'role_ids' => [$this->cashierRole->id, $this->role('retail', 'payments_approver')->id],
        'scope_type' => 'branch',
        'branch_id' => $this->juba->id,
    ];

    assign($input)->assertSessionHasErrors(['acknowledge_sod' => 'Cashiers approving payments needs a second look.']);
    expect(RoleAssignment::acrossTenants()->where('membership_id', $this->grantee->id)->count())->toBe(0);

    assign([...$input, 'acknowledge_sod' => '1'])->assertSessionHasNoErrors();
    expect(RoleAssignment::acrossTenants()->where('membership_id', $this->grantee->id)->count())->toBe(2);
});

it('gives none of the roles when one pair is blocked', function () {
    $this->actingAs($this->owner->user);

    assign([
        'membership_id' => $this->grantee->id,
        'role_ids' => [$this->role('retail', 'payments_clerk')->id, $this->role('retail', 'payments_approver')->id],
        'scope_type' => 'organization',
    ])->assertSessionHasErrors(['role_id' => 'Payments approver: The person who creates a payment must not approve it.']);

    expect(RoleAssignment::acrossTenants()->where('membership_id', $this->grantee->id)->count())->toBe(0);
});
