<?php

use App\Modules\Authorization\Models\RoleAssignment;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Structure\Models\Branch;
use App\Modules\Structure\Models\Department;
use App\Modules\Structure\Models\Position;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

arch('no debugging helpers are left in application code')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r'])
    ->not->toBeUsed();

arch('module models are Eloquent models in a Models namespace')
    ->expect('App\Modules\*\Models')
    ->toExtend(Model::class);

arch('module enums are backed enums')
    ->expect('App\Modules\*\Enums')
    ->toBeStringBackedEnums();

arch('shared support code does not depend on HTTP controllers')
    ->expect('App\Support')
    ->not->toUse('App\Http\Controllers');

arch('modules do not depend on HTTP controllers')
    ->expect('App\Modules')
    ->not->toUse('App\Http\Controllers');

arch('tenant-owned models use the tenant guard', function () {
    $tenantOwned = [
        Branch::class,
        Department::class,
        Position::class,
        OrganizationMembership::class,
        RoleAssignment::class,
    ];

    foreach ($tenantOwned as $model) {
        expect(class_uses_recursive($model))->toHaveKey(BelongsToOrganization::class);
    }
});
