<?php

use App\Modules\Authorization\Support\AccessResolver;
use App\Modules\Authorization\Support\Scope;
use App\Modules\Authorization\Support\ScopeTree;
use App\Modules\Structure\Models\Department;
use Tests\Support\AuthorizationFixtures;

uses(AuthorizationFixtures::class);

beforeEach(fn () => $this->setUpAuthorizationFixtures());

it('lets the organization scope contain everything', function () {
    $tree = new ScopeTree;

    expect($tree->contains(Scope::organization(), Scope::branch($this->juba->id)))->toBeTrue()
        ->and($tree->contains(Scope::organization(), Scope::department($this->headOffice->id)))->toBeTrue()
        ->and($tree->contains(Scope::organization(), Scope::resource('register', 'R1')))->toBeTrue();
});

it('lets a branch contain its departments and their sub-departments only', function () {
    $tree = new ScopeTree;
    $juba = Scope::branch($this->juba->id);

    expect($tree->contains($juba, $juba))->toBeTrue()
        ->and($tree->contains($juba, Scope::department($this->jubaSales->id)))->toBeTrue()
        ->and($tree->contains($juba, Scope::department($this->jubaSalesFloor->id)))->toBeTrue()
        ->and($tree->contains($juba, Scope::branch($this->wau->id)))->toBeFalse()
        ->and($tree->contains($juba, Scope::department($this->headOffice->id)))->toBeFalse()
        ->and($tree->contains($juba, Scope::organization()))->toBeFalse()
        ->and($tree->contains($juba, Scope::resource('register', 'R1')))->toBeFalse();
});

it('lets a department contain its descendants but not its parent', function () {
    $tree = new ScopeTree;

    expect($tree->contains(Scope::department($this->jubaSales->id), Scope::department($this->jubaSalesFloor->id)))->toBeTrue()
        ->and($tree->contains(Scope::department($this->jubaSalesFloor->id), Scope::department($this->jubaSales->id)))->toBeFalse()
        ->and($tree->contains(Scope::department($this->jubaSales->id), Scope::branch($this->juba->id)))->toBeFalse();
});

it('treats a resource scope as containing only itself', function () {
    $tree = new ScopeTree;

    expect($tree->contains(Scope::resource('register', 'R1'), Scope::resource('register', 'R1')))->toBeTrue()
        ->and($tree->contains(Scope::resource('register', 'R1'), Scope::resource('register', 'R2')))->toBeFalse();
});

it('survives a department cycle without looping', function () {
    $a = Department::query()->create(['name' => 'A', 'code' => 'CYA']);
    $b = Department::query()->create(['name' => 'B', 'code' => 'CYB', 'parent_department_id' => $a->id]);
    $a->forceFill(['parent_department_id' => $b->id])->save();

    expect((new ScopeTree)->contains(Scope::branch($this->juba->id), Scope::department($a->id)))->toBeFalse();
});

it('resolves permissions only where the assignment applies', function () {
    $member = $this->member();
    $this->grant($member, 'retail', 'auditor', Scope::department($this->jubaSales->id));
    $retailId = $this->role('retail', 'auditor')->application_id;
    $access = new AccessResolver;

    expect($access->permissionsAt($member, $retailId, Scope::department($this->jubaSalesFloor->id)))->toBe(['retail.reports.view'])
        ->and($access->permissionsAt($member, $retailId, Scope::branch($this->juba->id)))->toBe([])
        ->and($access->permissionsAt($member, $retailId, Scope::department($this->headOffice->id)))->toBe([])
        ->and($access->holds($member, 'retail.reports.view', Scope::organization()))->toBeFalse();
});
