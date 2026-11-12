<?php

use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\TimesheetEntry;

beforeEach(function () {
    // No time travel: event tokens are checked against the real clock.
    $this->publishEventKeys();
    $this->actingAsMember('acme', PAYROLL_ALL);
    setUpPayrollWithRules();
});

function joined(string $reference = 'emp-1', array $overrides = [], string $source = 'hr')
{
    return test()->sendEvent('payroll.employee.joined', "employee:{$reference}:joined:".md5(json_encode($overrides)), $overrides + [
        'employee_reference' => $reference,
        'employee_number' => 'E-00001',
        'first_name' => 'Akol',
        'last_name' => 'Deng',
        'work_email' => 'akol@acme.test',
        'branch_id' => null,
        'department_id' => null,
        'job_title' => 'Cashier',
        'started_on' => '2026-01-05',
    ], source: $source);
}

it('adds someone HR hired, once, keeping HR\'s number', function () {
    joined()->assertCreated();
    joined()->assertOk()->assertJsonPath('status', 'duplicate');

    $employee = $this->asTenant('acme', fn () => Employee::query()->sole());
    expect($employee)
        ->employee_number->toBe('E-00001')
        ->source_app->toBe('hr')
        ->source_reference->toBe('emp-1')
        ->job_title->toBe('Cashier')
        ->isLinked()->toBeTrue();
});

it('refreshes work details on a later event, and records leaving', function () {
    joined()->assertCreated();
    joined(overrides: ['job_title' => 'Supervisor'])->assertCreated();

    $this->sendEvent('payroll.employee.left', 'employee:emp-1:left', ['employee_reference' => 'emp-1', 'left_on' => '2026-03-10'])->assertCreated();

    $employee = $this->asTenant('acme', fn () => Employee::query()->sole());
    expect($employee)->job_title->toBe('Supervisor')->status->toBe(Employee::LEFT)
        ->and($employee->left_on->toDateString())->toBe('2026-03-10');
});

it('refuses pay in an event, and asks to retry for someone not here yet', function () {
    joined(overrides: ['salary' => 1000])->assertUnprocessable();

    $this->sendEvent('payroll.employee.left', 'employee:ghost:left', ['employee_reference' => 'ghost', 'left_on' => '2026-03-10'])
        ->assertStatus(503)
        ->assertHeader('Retry-After', '60');

    $this->sendEvent('finance.invoice.issued', 'x', ['invoice_number' => '1'])->assertUnprocessable()->assertJsonPath('error', 'wrong_module');
});

it('pays hourly staff for the hours a product sent, overtime at time and a half', function () {
    joined(source: 'restaurant')->assertCreated();
    $employee = $this->asTenant('acme', fn () => Employee::query()->sole());
    $this->put($this->workspaceUrl('acme', "employees/{$employee->id}"), ['pay_basis' => 'hourly'])->assertSessionHasNoErrors();
    $this->post($this->workspaceUrl('acme', "employees/{$employee->id}/pay"), ['effective_from' => '2026-01-01', 'amount' => '500'])->assertSessionHasNoErrors();

    $timesheet = fn (float $hours) => $this->sendEvent('payroll.timesheet.submitted', "timesheet:emp-1:2026-03-02:{$hours}", [
        'employee_reference' => 'emp-1',
        'period_start' => '2026-03-02',
        'period_end' => '2026-03-03',
        'entries' => [
            ['date' => '2026-03-02', 'hours' => $hours, 'kind' => 'regular'],
            ['date' => '2026-03-03', 'hours' => 2, 'kind' => 'overtime'],
        ],
    ], source: 'restaurant');

    $timesheet(6)->assertCreated();
    // The day sent again replaces it.
    $timesheet(8)->assertCreated();
    $this->asTenant('acme', fn () => expect(TimesheetEntry::query()->count())->toBe(2));

    $this->post($this->workspaceUrl('acme', 'runs'), ['month' => '2026-03', 'pay_date' => '2026-03-31'])->assertSessionHasNoErrors();
    $payslip = $this->asTenant('acme', fn () => Payslip::query()->sole());
    // 8 h x 500 + 2 h x 750.
    expect($payslip->earnings_minor)->toBe(550_000)
        ->and((float) $payslip->hours_paid)->toBe(10.0);

    // Approved: those days are paid and stay as paid.
    $run = $this->asTenant('acme', fn () => PayrollRun::query()->sole());
    $this->asTenant('acme', fn () => $run->forceFill(['status' => PayrollRun::APPROVED])->save());
    $timesheet(9)->assertUnprocessable()->assertJsonPath('error', 'not_applicable');
});
