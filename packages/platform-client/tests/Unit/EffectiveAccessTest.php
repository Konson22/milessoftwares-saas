<?php

use Miles\PlatformClient\Api\EffectiveAccess;
use Miles\PlatformClient\Tests\Fixtures\FakePlatform;

function access(array $roles, bool $owner = false, bool $allowed = true): EffectiveAccess
{
    $platform = new FakePlatform;
    $platform->grant('acme', FakePlatform::subject(), $roles, $owner, $allowed);

    return EffectiveAccess::fromArray($platform->access[FakePlatform::uuid('acme').':'.FakePlatform::subject()]);
}

it('limits a branch-scoped permission to that branch', function () {
    $access = access([FakePlatform::role('pharmacist', ['pharmacy.dispense'], ['scope_type' => 'branch', 'branch_id' => 'b-1'])]);

    expect($access->has('pharmacy.dispense'))->toBeTrue()
        ->and($access->allowsIn('pharmacy.dispense', branchId: 'b-1'))->toBeTrue()
        ->and($access->allowsIn('pharmacy.dispense', branchId: 'b-2'))->toBeFalse()
        ->and($access->allowsIn('pharmacy.dispense'))->toBeFalse()
        ->and($access->branchesFor('pharmacy.dispense'))->toBe(['b-1']);
});

it('lets an organization-scoped grant cover every branch', function () {
    $access = access([
        FakePlatform::role('pharmacist', ['pharmacy.dispense'], ['scope_type' => 'branch', 'branch_id' => 'b-1']),
        FakePlatform::role('manager', ['pharmacy.dispense']),
    ]);

    expect($access->allowsIn('pharmacy.dispense', branchId: 'b-9'))->toBeTrue()
        ->and($access->branchesFor('pharmacy.dispense'))->toBeNull();
});

it('limits a department-scoped permission to that department', function () {
    $access = access([FakePlatform::role('storekeeper', ['pharmacy.stock.adjust'], ['scope_type' => 'department', 'department_id' => 'd-1'])]);

    expect($access->allowsIn('pharmacy.stock.adjust', departmentId: 'd-1'))->toBeTrue()
        ->and($access->allowsIn('pharmacy.stock.adjust', branchId: 'b-1', departmentId: 'd-2'))->toBeFalse();
});

it('never widens a resource-scoped grant', function () {
    $access = access([FakePlatform::role('auditor', ['pharmacy.reports.view'], ['scope_type' => 'resource', 'resource_type' => 'report', 'resource_id' => 'r-1'])]);

    expect($access->allowsIn('pharmacy.reports.view', branchId: 'b-1'))->toBeFalse()
        ->and($access->branchesFor('pharmacy.reports.view'))->toBe([]);
});

it('grants owners everything at organization scope', function () {
    $access = access([], owner: true);

    expect($access->has('pharmacy.anything'))->toBeTrue()
        ->and($access->allowsIn('pharmacy.anything', branchId: 'b-1'))->toBeTrue()
        ->and($access->branchesFor('pharmacy.anything'))->toBeNull();
});

it('grants nothing when access is not allowed, whatever the roles', function () {
    $access = access([FakePlatform::role('pharmacist', ['pharmacy.dispense'])], allowed: false);

    expect($access->has('pharmacy.dispense'))->toBeFalse()
        ->and($access->allowsIn('pharmacy.dispense', branchId: 'b-1'))->toBeFalse();
});
