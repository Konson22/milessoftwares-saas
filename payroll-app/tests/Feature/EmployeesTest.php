<?php

use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->actingAsMember('acme', PAYROLL_ALL);
    setUpPayrollWithRules();
    $this->juba = $this->platform()->branch('acme', 'Juba', 'JUB');
    $this->wau = $this->platform()->branch('acme', 'Wau', 'WAU');
});

it('numbers people and keeps each organization\'s to itself', function () {
    addEmployee('Akol', details: ['branch_id' => $this->juba]);
    addEmployee('Nyandeng', details: ['branch_id' => $this->wau]);

    $this->asTenant('acme', fn () => expect(Employee::query()->orderBy('employee_number')->pluck('employee_number')->all())->toBe(['P-00001', 'P-00002']));

    $this->actingAsMember('globex', PAYROLL_ALL);
    setUpPayrollWithRules('globex');
    $this->get($this->workspaceUrl('globex', 'employees'))->assertInertia(fn (Assert $page) => $page->has('employees', 0));
});

it('shows a branch member only their branch, and nobody\'s pay', function () {
    $akol = addEmployee('Akol', details: ['branch_id' => $this->juba]);
    $nyandeng = addEmployee('Nyandeng', details: ['branch_id' => $this->wau]);

    $this->actingAsMember('acme', ['payroll.employees.view'], branch: $this->juba);
    $this->get($this->workspaceUrl('acme', 'employees'))
        ->assertInertia(fn (Assert $page) => $page->has('employees', 1)->where('employees.0.name', 'Akol Deng')->where('employees.0.payMinor', null));
    $this->get($this->workspaceUrl('acme', "employees/{$akol->id}"))->assertInertia(fn (Assert $page) => $page->where('pay', null)->where('paymentDetails', null));
    $this->get($this->workspaceUrl('acme', "employees/{$nyandeng->id}"))->assertNotFound();
});

it('keeps payment details encrypted', function () {
    $akol = addEmployee('Akol');

    $this->put($this->workspaceUrl('acme', "employees/{$akol->id}/payment-details"), ['method' => 'bank', 'bank_name' => 'KCB', 'account_number' => '1234567890'])->assertSessionHasNoErrors();

    $raw = DB::table('employees')->where('id', $akol->id)->value('payment_details');
    expect($raw)->not->toContain('1234567890');
    $this->get($this->workspaceUrl('acme', "employees/{$akol->id}"))->assertInertia(fn (Assert $page) => $page->where('paymentDetails.account_number', '1234567890'));
});

it('leaves work details of someone HR keeps to HR', function () {
    $this->publishEventKeys();
    $this->sendEvent('payroll.employee.joined', 'employee:emp-1', [
        'employee_reference' => 'emp-1', 'first_name' => 'Akol', 'last_name' => 'Deng', 'started_on' => '2026-01-05',
    ])->assertCreated();
    $akol = $this->asTenant('acme', fn () => Employee::query()->sole());

    $this->put($this->workspaceUrl('acme', "employees/{$akol->id}"), ['pay_basis' => 'hourly', 'first_name' => 'Changed', 'last_name' => 'Name', 'hired_on' => '2020-01-01'])->assertSessionHasNoErrors();
    $akol->refresh();
    expect($akol)->first_name->toBe('Akol')->pay_basis->toBe('hourly');

    $this->post($this->workspaceUrl('acme', "employees/{$akol->id}/leave"), ['left_on' => '2026-03-01'])->assertForbidden();
});

it('validates income tax bands', function () {
    $rule = fn (array $bands, string $from = '2027-01-01') => $this->post($this->workspaceUrl('acme', 'settings/rules'), [
        'name' => 'Next year', 'effective_from' => $from, 'bands' => $bands, 'employee_rate' => '8', 'employer_rate' => '17',
    ]);

    $rule([['up_to' => '100', 'rate' => '0'], ['up_to' => '50', 'rate' => '10'], ['up_to' => null, 'rate' => '20']])->assertSessionHasErrors('bands.1.up_to');
    $rule([['up_to' => null, 'rate' => '0'], ['up_to' => null, 'rate' => '10']])->assertSessionHasErrors('bands.0.up_to');
    $rule([['up_to' => '100', 'rate' => '10']])->assertSessionHasErrors('bands.0.up_to');
    $rule([['up_to' => null, 'rate' => '10']], '2026-01-01')->assertSessionHasErrors('effective_from');
    $rule([['up_to' => '100', 'rate' => '0'], ['up_to' => null, 'rate' => '10']])->assertSessionHasNoErrors();
});
