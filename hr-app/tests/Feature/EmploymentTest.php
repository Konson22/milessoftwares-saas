<?php

use App\Modules\Employees\Models\Contract;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmploymentRecord;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->juba = $this->platform()->branch('acme', 'Juba', 'JUB');
    $this->wau = $this->platform()->branch('acme', 'Wau', 'WAU');
    $this->supervisor = $this->platform()->position('acme', 'Supervisor');

    $this->actingAsMember('acme', ['hr.employees.view', 'hr.employees.manage', 'hr.employment.manage']);
    $this->post($this->workspaceUrl('acme', 'employees'), [
        'first_name' => 'Akol',
        'last_name' => 'Deng',
        'branch_id' => $this->juba,
        'hired_on' => '2026-01-05',
        'contract_type' => 'fixed_term',
        'ends_on' => '2026-12-31',
    ])->assertSessionHasNoErrors();
    $this->employee = $this->asTenant('acme', fn () => Employee::query()->sole());
});

it('records a transfer and keeps the history in order', function () {
    $this->post($this->workspaceUrl('acme', "employees/{$this->employee->id}/moves"), [
        'kind' => 'transfer',
        'effective_on' => '2026-06-01',
        'branch_id' => $this->wau,
        'note' => 'Opening the Wau branch',
    ])->assertSessionHasNoErrors();

    $this->post($this->workspaceUrl('acme', "employees/{$this->employee->id}/moves"), [
        'kind' => 'promotion',
        'effective_on' => '2026-09-01',
        'branch_id' => $this->wau,
        'position_id' => $this->supervisor,
    ])->assertSessionHasNoErrors();

    // Nothing may take effect before the latest change, and a move must change something.
    $this->post($this->workspaceUrl('acme', "employees/{$this->employee->id}/moves"), ['kind' => 'transfer', 'effective_on' => '2026-08-01', 'branch_id' => $this->juba])
        ->assertSessionHasErrors('effective_on');
    $this->post($this->workspaceUrl('acme', "employees/{$this->employee->id}/moves"), ['kind' => 'change', 'effective_on' => '2026-10-01', 'branch_id' => $this->wau, 'position_id' => $this->supervisor])
        ->assertSessionHasErrors('branch_id');

    $this->get($this->workspaceUrl('acme', "employees/{$this->employee->id}"))
        ->assertInertia(fn (Assert $page) => $page
            ->component('employees/show')
            ->where('employee.branch', 'Wau')
            ->where('employee.position', 'Supervisor')
            ->has('records', 3)
            ->where('records.0.kind', 'promotion')
            ->where('records.0.jobTitle', 'Supervisor')
            ->where('records.1.note', 'Opening the Wau branch')
            ->where('records.2.kind', 'hired'));
});

it('needs hr.employment.manage in both the branch they leave and the one they go to', function () {
    $this->actingAsMember('acme', ['hr.employees.view', 'hr.employment.manage'], branch: $this->juba);

    $this->post($this->workspaceUrl('acme', "employees/{$this->employee->id}/moves"), ['kind' => 'transfer', 'effective_on' => '2026-06-01', 'branch_id' => $this->wau])
        ->assertForbidden();

    $this->actingAsMember('acme', ['hr.employees.view']);
    $this->post($this->workspaceUrl('acme', "employees/{$this->employee->id}/leave"), ['left_on' => '2026-06-01', 'reason' => 'resigned'])
        ->assertForbidden();

    expect($this->asTenant('acme', fn () => EmploymentRecord::query()->count()))->toBe(1);
});

it('renews a contract: the old one ends the day before the new one starts', function () {
    $this->post($this->workspaceUrl('acme', "employees/{$this->employee->id}/contracts"), [
        'contract_type' => 'permanent',
        'starts_on' => '2027-01-01',
        'notice_days' => 30,
    ])->assertSessionHasNoErrors();

    $this->post($this->workspaceUrl('acme', "employees/{$this->employee->id}/contracts"), ['contract_type' => 'casual', 'starts_on' => '2026-12-01'])
        ->assertSessionHasErrors('starts_on');

    $this->asTenant('acme', function () {
        $contracts = Contract::query()->orderBy('starts_on')->get();
        expect($contracts)->toHaveCount(2)
            ->and($contracts[0]->status)->toBe(Contract::ENDED)
            ->and($contracts[0]->ended_on?->toDateString())->toBe('2026-12-31')
            ->and($contracts[1]->status)->toBe(Contract::CURRENT)
            ->and($contracts[1]->notice_days)->toBe(30)
            ->and(Employee::query()->sole()->employment_type->value)->toBe('permanent');
    });
});

it('lets someone leave: last day, reason, contract ended, reports unassigned, no further changes', function () {
    $this->post($this->workspaceUrl('acme', 'employees'), [
        'first_name' => 'Report', 'last_name' => 'Deng', 'hired_on' => '2026-02-01', 'contract_type' => 'casual', 'manager_id' => $this->employee->id,
    ])->assertSessionHasNoErrors();

    $this->post($this->workspaceUrl('acme', "employees/{$this->employee->id}/leave"), [
        'left_on' => '2026-10-31',
        'reason' => 'contract_ended',
        'note' => 'Did not renew',
    ])->assertSessionHasNoErrors();

    $this->asTenant('acme', function () {
        $left = Employee::query()->findOrFail($this->employee->id);
        expect($left->status)->toBe(Employee::LEFT)
            ->and($left->left_on?->toDateString())->toBe('2026-10-31')
            ->and($left->leaving_reason?->value)->toBe('contract_ended')
            ->and(Contract::query()->where('employee_id', $left->id)->sole()->status)->toBe(Contract::ENDED)
            ->and(Employee::query()->where('first_name', 'Report')->value('manager_id'))->toBeNull()
            ->and(EmploymentRecord::query()->where('kind', 'left')->sole()->note)->toBe('Contract ended: Did not renew');
    });

    $this->post($this->workspaceUrl('acme', "employees/{$this->employee->id}/moves"), ['kind' => 'transfer', 'effective_on' => '2026-11-01', 'branch_id' => $this->wau])
        ->assertSessionHasErrors('employee');
    $this->put($this->workspaceUrl('acme', "employees/{$this->employee->id}"), ['first_name' => 'X', 'last_name' => 'Y'])
        ->assertForbidden();

    $this->get($this->workspaceUrl('acme', "employees/{$this->employee->id}"))
        ->assertInertia(fn (Assert $page) => $page
            ->where('employee.status', 'left')
            ->where('employee.leavingReason', 'Contract ended')
            ->where('can.employment', false)
            ->where('options', null));
});

it('counts staff on the home page and lists contracts ending soon', function () {
    $this->travelTo('2026-12-10');

    $this->get($this->workspaceUrl('acme'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('home')
            ->where('summary.headcount', 1)
            ->has('summary.endingSoon', 1)
            ->where('summary.endingSoon.0.what', 'Contract ends')
            ->where('summary.endingSoon.0.on', '2026-12-31'));
});
