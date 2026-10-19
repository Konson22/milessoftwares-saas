<?php

use App\Modules\Employees\Models\Contract;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmploymentRecord;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->juba = $this->platform()->branch('acme', 'Juba', 'JUB');
    $this->wau = $this->platform()->branch('acme', 'Wau', 'WAU');
    $this->sales = $this->platform()->department('acme', 'Juba sales', $this->juba, 'JSALES');
    $this->finance = $this->platform()->department('acme', 'Finance');
    $this->clerk = $this->platform()->position('acme', 'Sales clerk', $this->sales, 'CLERK');
});

function hire(array $overrides = []): array
{
    return $overrides + [
        'first_name' => 'Akol',
        'last_name' => 'Deng',
        'work_email' => 'Akol@Example.test',
        'hired_on' => '2026-10-01',
        'contract_type' => 'permanent',
    ];
}

it('hires someone: an employee number, a hired record and a first contract', function () {
    $this->actingAsMember('acme', ['hr.employees.view', 'hr.employees.manage']);

    $this->post($this->workspaceUrl('acme', 'employees'), hire([
        'branch_id' => $this->juba,
        'department_id' => $this->sales,
        'position_id' => $this->clerk,
        'probation_ends_on' => '2027-01-01',
    ]))->assertRedirect();

    $this->asTenant('acme', function () {
        $employee = Employee::query()->sole();
        expect($employee->employee_number)->toBe('E-00001')
            ->and($employee->work_email)->toBe('akol@example.test')
            ->and($employee->status)->toBe(Employee::ACTIVE)
            ->and($employee->branch_id)->toBe($this->juba)
            ->and($employee->employment_type->value)->toBe('permanent');

        $record = EmploymentRecord::query()->sole();
        expect($record->kind->value)->toBe('hired')
            ->and($record->effective_on->toDateString())->toBe('2026-10-01')
            ->and($record->position_id)->toBe($this->clerk);

        $contract = Contract::query()->sole();
        expect($contract->status)->toBe(Contract::CURRENT)
            ->and($contract->starts_on->toDateString())->toBe('2026-10-01')
            ->and($contract->probation_ends_on?->toDateString())->toBe('2027-01-01');
    });

    $this->post($this->workspaceUrl('acme', 'employees'), hire(['first_name' => 'Nyibol']))->assertRedirect();
    expect($this->asTenant('acme', fn () => Employee::query()->where('first_name', 'Nyibol')->value('employee_number')))->toBe('E-00002');
});

it('refuses a department of another branch, a closed or unknown structure id, and a fixed term without an end', function () {
    $this->actingAsMember('acme', ['hr.employees.view', 'hr.employees.manage']);

    $this->post($this->workspaceUrl('acme', 'employees'), hire(['branch_id' => $this->wau, 'department_id' => $this->sales]))
        ->assertSessionHasErrors('department_id');
    $this->post($this->workspaceUrl('acme', 'employees'), hire(['branch_id' => '0199a0b0-0000-7000-8000-00000000dead']))
        ->assertSessionHasErrors('branch_id');
    $this->post($this->workspaceUrl('acme', 'employees'), hire(['contract_type' => 'fixed_term']))
        ->assertSessionHasErrors('ends_on');

    // A company-wide department goes with any branch.
    $this->post($this->workspaceUrl('acme', 'employees'), hire(['branch_id' => $this->wau, 'department_id' => $this->finance]))
        ->assertSessionHasNoErrors();

    expect($this->asTenant('acme', fn () => Employee::query()->count()))->toBe(1);
});

it('shows a branch-scoped member only the staff of their branch', function () {
    $this->actingAsMember('acme', ['hr.employees.view', 'hr.employees.manage']);
    $this->post($this->workspaceUrl('acme', 'employees'), hire(['first_name' => 'Juba', 'branch_id' => $this->juba]));
    $this->post($this->workspaceUrl('acme', 'employees'), hire(['first_name' => 'Wau', 'branch_id' => $this->wau]));
    $wau = $this->asTenant('acme', fn () => Employee::query()->where('first_name', 'Wau')->sole());

    $this->actingAsMember('acme', ['hr.employees.view', 'hr.employees.manage'], branch: $this->juba);

    $this->get($this->workspaceUrl('acme', 'employees'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('employees/index')
            ->has('employees', 1)
            ->where('employees.0.name', 'Juba Deng')
            ->where('employees.0.branch', 'Juba'));

    // Out of reach: not found, and cannot be changed or hired into.
    $this->get($this->workspaceUrl('acme', "employees/{$wau->id}"))->assertNotFound();
    $this->put($this->workspaceUrl('acme', "employees/{$wau->id}"), hire())->assertNotFound();
    $this->post($this->workspaceUrl('acme', 'employees'), hire(['branch_id' => $this->wau]))->assertForbidden();
});

it('lets a department-scoped line manager see their department and nothing else', function () {
    $this->actingAsMember('acme', ['hr.employees.view', 'hr.employees.manage']);
    $this->post($this->workspaceUrl('acme', 'employees'), hire(['first_name' => 'Seller', 'branch_id' => $this->juba, 'department_id' => $this->sales]));
    $this->post($this->workspaceUrl('acme', 'employees'), hire(['first_name' => 'Counter', 'branch_id' => $this->juba, 'department_id' => $this->finance]));

    // Signed in, then given a department-scoped role only.
    $user = $this->actingAsMember('acme');
    $this->platform()->grant('acme', (string) $user->getAuthIdentifier(), [
        ['permissions' => ['hr.employees.view'], 'scope' => ['scope_type' => 'department', 'department_id' => $this->sales]],
    ]);

    $this->get($this->workspaceUrl('acme', 'employees'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('employees', 1)
            ->where('employees.0.name', 'Seller Deng')
            ->where('canHire', false));
});

it('searches by name and number, and lists leavers on request', function () {
    $this->actingAsMember('acme', ['hr.employees.view', 'hr.employees.manage', 'hr.employment.manage']);
    $this->post($this->workspaceUrl('acme', 'employees'), hire(['first_name' => 'Akol']));
    $this->post($this->workspaceUrl('acme', 'employees'), hire(['first_name' => 'Nyibol', 'last_name' => 'Garang']));
    $nyibol = $this->asTenant('acme', fn () => Employee::query()->where('first_name', 'Nyibol')->sole());
    $this->post($this->workspaceUrl('acme', "employees/{$nyibol->id}/leave"), ['left_on' => '2026-10-15', 'reason' => 'resigned']);

    $this->get($this->workspaceUrl('acme', 'employees?q=e-00001'))->assertInertia(fn (Assert $page) => $page->has('employees', 1)->where('employees.0.name', 'Akol Deng'));
    $this->get($this->workspaceUrl('acme', 'employees?q=garang'))->assertInertia(fn (Assert $page) => $page->has('employees', 0));
    $this->get($this->workspaceUrl('acme', 'employees?q=garang&status=left'))->assertInertia(fn (Assert $page) => $page->has('employees', 1));
});

it('changes work details and refuses a manager who reports to them', function () {
    $this->actingAsMember('acme', ['hr.employees.view', 'hr.employees.manage']);
    $this->post($this->workspaceUrl('acme', 'employees'), hire(['first_name' => 'Boss']));
    $boss = $this->asTenant('acme', fn () => Employee::query()->sole());
    $this->post($this->workspaceUrl('acme', 'employees'), hire(['first_name' => 'Staff', 'manager_id' => $boss->id]));
    $staff = $this->asTenant('acme', fn () => Employee::query()->where('first_name', 'Staff')->sole());

    expect($staff->manager_id)->toBe($boss->id);

    $this->put($this->workspaceUrl('acme', "employees/{$boss->id}"), hire(['first_name' => 'Boss', 'manager_id' => $staff->id]))
        ->assertSessionHasErrors('manager_id');
    $this->put($this->workspaceUrl('acme', "employees/{$boss->id}"), hire(['first_name' => 'Boss', 'manager_id' => $boss->id]))
        ->assertSessionHasErrors('manager_id');

    $this->put($this->workspaceUrl('acme', "employees/{$staff->id}"), hire(['first_name' => 'Staffer', 'preferred_name' => 'Stef', 'work_phone' => '+211 900 000 000']))
        ->assertSessionHasNoErrors();

    $this->asTenant('acme', function () use ($staff) {
        $fresh = Employee::query()->findOrFail($staff->id);
        expect($fresh->first_name)->toBe('Staffer')
            ->and($fresh->displayName())->toBe('Stef Deng')
            ->and($fresh->manager_id)->toBeNull();
    });
});

it('needs hr.employees.manage to hire, and never shows another workspace\'s staff', function () {
    $this->actingAsMember('acme', ['hr.employees.view', 'hr.employees.manage']);
    $this->post($this->workspaceUrl('acme', 'employees'), hire());
    $acme = $this->asTenant('acme', fn () => Employee::query()->sole());

    $this->actingAsMember('acme', ['hr.employees.view']);
    $this->get($this->workspaceUrl('acme', 'employees/create'))->assertForbidden();
    $this->post($this->workspaceUrl('acme', 'employees'), hire())->assertForbidden();

    $this->actingAsMember('globex', ['hr.employees.view']);
    $this->get($this->workspaceUrl('globex', 'employees'))->assertInertia(fn (Assert $page) => $page->has('employees', 0));
    $this->get($this->workspaceUrl('globex', "employees/{$acme->id}"))->assertNotFound();
});
