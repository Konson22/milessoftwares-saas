<?php

use App\Modules\Authorization\Support\Scope;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Structure\Models\Branch;
use App\Modules\Structure\Models\Department;
use App\Support\Tenancy\TenantContext;
use Inertia\Testing\AssertableInertia;
use Tests\Support\AuthorizationFixtures;

uses(AuthorizationFixtures::class);

beforeEach(function () {
    $this->prepareAuthorizationFixtures();
    $this->slug = $this->organization->primarySlug->slug;

    $this->structureAdmin = $this->member();
    $this->grant($this->structureAdmin, 'platform', 'structure_administrator');

    $this->plainMember = $this->member();
    $this->branchAccessAdmin = $this->member();
    $this->grant($this->branchAccessAdmin, 'platform', 'access_administrator', Scope::branch($this->juba->id));

    $other = Organization::factory()->create();
    [$this->foreignBranch, $this->foreignDepartment] = app(TenantContext::class)->run($other, fn () => [
        Branch::query()->create(['name' => 'Foreign', 'code' => 'FOR']),
        Department::query()->create(['name' => 'Foreign dept', 'code' => 'FORD']),
    ]);

    app(TenantContext::class)->clear();
});

function onWorkspace(string $path): string
{
    return 'http://'.test()->slug.'.'.config('platform.root_domain').$path;
}

/**
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function inTenant(Closure $callback): mixed
{
    return app(TenantContext::class)->run(test()->organization, $callback);
}

it('shows the structure to owners and structure administrators', function (string $who) {
    $member = $who === 'owner' ? $this->owner : $this->structureAdmin;

    $this->actingAs($member->user)
        ->get(onWorkspace('/manage/structure'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('workspace/manage/structure')
            ->has('branches', 2)
            ->has('departments', 3)
            ->where('abilities.manageStructure', true));
})->with(['owner', 'structure administrator']);

it('forbids structure management to other members', function (string $who) {
    $member = $who === 'plain' ? $this->plainMember : $this->branchAccessAdmin;

    $this->actingAs($member->user)->get(onWorkspace('/manage/structure'))->assertForbidden();
    $this->post(onWorkspace('/manage/branches'), ['name' => 'Rumbek', 'code' => 'RUM'])->assertForbidden();

    expect(inTenant(fn () => Branch::query()->count()))->toBe(2);
})->with(['plain', 'branch access administrator']);

it('creates and archives a branch', function () {
    $this->actingAs($this->structureAdmin->user)
        ->post(onWorkspace('/manage/branches'), ['name' => 'Rumbek', 'code' => 'RUM', 'city' => 'Rumbek'])
        ->assertSessionHasNoErrors();

    $branch = inTenant(fn () => Branch::query()->where('code', 'RUM')->sole());

    $this->put(onWorkspace('/manage/branches/'.$branch->id), ['name' => 'Rumbek', 'code' => 'RUM', 'status' => 'archived'])
        ->assertSessionHasNoErrors();

    expect($branch->refresh()->status)->toBe('archived');
});

it('keeps codes unique within the organization only', function () {
    $this->actingAs($this->structureAdmin->user)
        ->post(onWorkspace('/manage/branches'), ['name' => 'Another Juba', 'code' => 'JUB'])
        ->assertSessionHasErrors('code');

    // FOR exists in the other organization, which does not matter here.
    $this->post(onWorkspace('/manage/branches'), ['name' => 'Fort', 'code' => 'FOR'])->assertSessionHasNoErrors();
});

it('returns 404 when updating another organization\'s branch by id', function () {
    $this->actingAs($this->owner->user)
        ->put(onWorkspace('/manage/branches/'.$this->foreignBranch->id), ['name' => 'Hijacked', 'code' => 'HIJ'])
        ->assertNotFound();

    expect(app(TenantContext::class)->run(Organization::query()->find($this->foreignBranch->organization_id), fn () => $this->foreignBranch->refresh()->name))->toBe('Foreign');
});

it('rejects foreign ids as department parents or branches', function () {
    $this->actingAs($this->owner->user)
        ->post(onWorkspace('/manage/departments'), ['name' => 'Sneaky', 'code' => 'SNK', 'parent_department_id' => $this->foreignDepartment->id])
        ->assertSessionHasErrors('parent_department_id');

    $this->post(onWorkspace('/manage/departments'), ['name' => 'Sneaky', 'code' => 'SNK', 'branch_id' => $this->foreignBranch->id])
        ->assertSessionHasErrors('branch_id');

    $this->post(onWorkspace('/manage/positions'), ['title' => 'Sneaky', 'code' => 'SNK', 'department_id' => $this->foreignDepartment->id])
        ->assertSessionHasErrors('department_id');
});

it('refuses to put a department under its own sub-department', function () {
    $this->actingAs($this->owner->user)
        ->put(onWorkspace('/manage/departments/'.$this->jubaSales->id), [
            'name' => 'Juba sales', 'code' => 'JSALES', 'parent_department_id' => $this->jubaSalesFloor->id,
        ])
        ->assertSessionHasErrors('parent_department_id');
});
