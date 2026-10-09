<?php

use App\Modules\Organizations\Models\Organization;
use App\Modules\Structure\Models\Branch;
use App\Modules\Structure\Models\Department;
use App\Support\Tenancy\CrossTenantWrite;
use App\Support\Tenancy\MissingTenantContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;

beforeEach(function () {
    $this->orgA = Organization::factory()->create();
    $this->orgB = Organization::factory()->create();
    $this->branchA = Branch::factory()->create(['organization_id' => $this->orgA->id]);
    $this->branchB = Branch::factory()->create(['organization_id' => $this->orgB->id]);
    $this->tenant = app(TenantContext::class);
});

it('fails closed when tenant data is queried without a tenant context', function () {
    Branch::query()->get();
})->throws(MissingTenantContext::class);

it('only returns the current tenant\'s rows', function () {
    $ids = $this->tenant->run($this->orgA, fn () => Branch::query()->pluck('id')->all());

    expect($ids)->toBe([$this->branchA->id]);
});

it('cannot read another tenant\'s record even with its id', function () {
    $found = $this->tenant->run($this->orgA, fn () => Branch::query()->find($this->branchB->id));

    expect($found)->toBeNull();
});

it('allows explicit cross-tenant queries only through acrossTenants()', function () {
    expect(Branch::acrossTenants()->count())->toBe(2);
});

it('fills organization_id from the tenant context on create', function () {
    $branch = $this->tenant->run($this->orgA, fn () => Branch::create(['name' => 'Juba', 'code' => 'JUB']));

    expect($branch->organization_id)->toBe($this->orgA->id);
});

it('ignores organization_id in mass assignment', function () {
    $branch = $this->tenant->run($this->orgA, fn () => Branch::create([
        'name' => 'Sneaky', 'code' => 'SNK', 'organization_id' => $this->orgB->id,
    ]));

    expect($branch->organization_id)->toBe($this->orgA->id);
});

it('refuses to create rows for another tenant', function () {
    $this->tenant->run($this->orgA, function () {
        $branch = new Branch(['name' => 'Sneaky', 'code' => 'SNK']);
        $branch->forceFill(['organization_id' => $this->orgB->id])->save();
    });
})->throws(CrossTenantWrite::class);

it('refuses to move a row to another tenant', function () {
    $this->tenant->run($this->orgA, function () {
        $branch = Branch::query()->findOrFail($this->branchA->id);
        $branch->forceFill(['organization_id' => $this->orgB->id])->save();
    });
})->throws(CrossTenantWrite::class);

it('requires a tenant context to create tenant rows without an explicit organization', function () {
    Branch::create(['name' => 'Orphan', 'code' => 'ORP']);
})->throws(MissingTenantContext::class);

it('restores the previous tenant context after run()', function () {
    $this->tenant->set($this->orgA);

    try {
        $this->tenant->run($this->orgB, fn () => throw new RuntimeException('boom'));
    } catch (RuntimeException) {
    }

    expect($this->tenant->id())->toBe($this->orgA->id);
});

it('blocks a department from referencing another tenant\'s branch at the database level', function () {
    Department::factory()->create([
        'organization_id' => $this->orgA->id,
        'branch_id' => $this->branchB->id,
    ]);
})->throws(QueryException::class, 'departments_organization_id_branch_id_foreign');

it('allows company-wide departments without a branch', function () {
    $department = Department::factory()->create(['organization_id' => $this->orgA->id, 'branch_id' => null]);

    expect($department->isCompanyWide())->toBeTrue();
});
