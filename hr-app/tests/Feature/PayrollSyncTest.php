<?php

use App\Modules\Employees\Models\Employee;
use Illuminate\Support\Facades\DB;

function hireAkol(): Employee
{
    test()->post(test()->workspaceUrl('acme', 'employees'), [
        'first_name' => 'Akol', 'last_name' => 'Deng', 'preferred_name' => 'Ak', 'work_email' => 'akol@acme.test',
        'job_title' => 'Cashier', 'hired_on' => '2026-01-05', 'contract_type' => 'permanent',
    ])->assertSessionHasNoErrors();

    return test()->asTenant('acme', fn () => Employee::query()->sole());
}

/**
 * @return list<array{type: string, payload: array<string, mixed>}>
 */
function payrollEvents(): array
{
    return DB::table('platform_outbox')->where('audience', 'payroll')->orderBy('created_at')->orderBy('id')->get()
        ->map(fn ($row) => ['type' => $row->event_type, 'payload' => json_decode($row->payload, true)])->all();
}

it('tells Payroll who was hired, what changed and when they left', function () {
    config(['platform-client.modules.payroll' => 'http://payroll.internal/payroll/events']);
    $this->actingAsMember('acme', ['hr.employees.view', 'hr.employees.manage', 'hr.employment.manage']);

    $akol = hireAkol();
    $this->put($this->workspaceUrl('acme', "employees/{$akol->id}"), ['first_name' => 'Akol', 'last_name' => 'Deng Garang'])->assertSessionHasNoErrors();
    $this->post($this->workspaceUrl('acme', "employees/{$akol->id}/leave"), ['left_on' => '2026-03-31', 'reason' => 'resigned'])->assertSessionHasNoErrors();

    $events = payrollEvents();
    expect(array_column($events, 'type'))->toBe(['payroll.employee.joined', 'payroll.employee.joined', 'payroll.employee.left'])
        ->and($events[0]['payload'])->toBe([
            'employee_reference' => $akol->id,
            'employee_number' => 'E-00001',
            'first_name' => 'Ak',
            'last_name' => 'Deng',
            'work_email' => 'akol@acme.test',
            'branch_id' => null,
            'department_id' => null,
            'job_title' => 'Cashier',
            'started_on' => '2026-01-05',
        ])
        ->and($events[1]['payload']['last_name'])->toBe('Deng Garang')
        ->and($events[2]['payload'])->toBe(['employee_reference' => $akol->id, 'left_on' => '2026-03-31']);
});

it('sends nothing while this deployment has no Payroll', function () {
    config(['platform-client.modules.payroll' => null]);
    $this->actingAsMember('acme', ['hr.employees.view', 'hr.employees.manage']);

    hireAkol();

    expect(payrollEvents())->toBe([]);
});
