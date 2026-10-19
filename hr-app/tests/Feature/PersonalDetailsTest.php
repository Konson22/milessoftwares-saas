<?php

use App\Modules\Employees\Models\AuditEntry;
use App\Modules\Employees\Models\Employee;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->actingAsMember('acme', ['hr.employees.view', 'hr.employees.manage']);
    $this->post($this->workspaceUrl('acme', 'employees'), [
        'first_name' => 'Akol', 'last_name' => 'Deng', 'hired_on' => '2026-01-05', 'contract_type' => 'permanent',
    ])->assertSessionHasNoErrors();
    $this->employee = $this->asTenant('acme', fn () => Employee::query()->sole());
});

it('keeps personal details encrypted and logs who changed which fields, never the values', function () {
    $this->actingAsMember('acme', ['hr.employees.view', 'hr.personal.view', 'hr.personal.manage']);

    $this->put($this->workspaceUrl('acme', "employees/{$this->employee->id}/personal"), [
        'date_of_birth' => '1990-04-12',
        'national_id' => 'SS-123456',
        'bank_name' => 'Equity Bank',
        'bank_account_number' => '0011223344',
    ])->assertSessionHasNoErrors();

    $raw = (string) DB::table('employee_personal_details')->value('details');
    expect($raw)->not->toContain('SS-123456')->not->toContain('0011223344');

    $this->get($this->workspaceUrl('acme', "employees/{$this->employee->id}/personal"))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('employees/personal')
            ->where('values.national_id', 'SS-123456')
            ->where('values.bank_account_number', '0011223344')
            ->where('values.passport_number', null)
            ->where('canManage', true));

    // Clearing a field is a change too; saving the same values is not.
    $this->put($this->workspaceUrl('acme', "employees/{$this->employee->id}/personal"), [
        'date_of_birth' => '1990-04-12', 'national_id' => 'SS-123456', 'bank_name' => 'Equity Bank',
    ])->assertSessionHasNoErrors();
    $this->put($this->workspaceUrl('acme', "employees/{$this->employee->id}/personal"), [
        'date_of_birth' => '1990-04-12', 'national_id' => 'SS-123456', 'bank_name' => 'Equity Bank',
    ])->assertSessionHasNoErrors();

    $this->asTenant('acme', function () {
        $log = AuditEntry::query()->orderBy('created_at')->orderBy('id')->get();
        expect($log->pluck('action')->all())->toBe(['personal.updated', 'personal.viewed', 'personal.updated'])
            ->and($log[0]->fields)->toBe(['date_of_birth', 'national_id', 'bank_name', 'bank_account_number'])
            ->and($log[2]->fields)->toBe(['bank_account_number']);
    });

    expect(json_encode(DB::table('hr_audit_log')->get()))->not->toContain('SS-123456');
});

it('is for organization-wide holders of the personal permissions only', function () {
    $branch = $this->platform()->branch('acme', 'Juba');

    // HR officers keep records but never see personal or bank details.
    $this->actingAsMember('acme', ['hr.employees.view', 'hr.employees.manage', 'hr.employment.manage']);
    $this->get($this->workspaceUrl('acme', "employees/{$this->employee->id}/personal"))->assertForbidden();
    $this->get($this->workspaceUrl('acme', "employees/{$this->employee->id}"))
        ->assertInertia(fn (Assert $page) => $page->where('can.personal', false));

    $this->actingAsMember('acme', ['hr.employees.view', 'hr.personal.view'], branch: $branch);
    $this->get($this->workspaceUrl('acme', "employees/{$this->employee->id}/personal"))->assertForbidden();

    $this->actingAsMember('acme', ['hr.employees.view', 'hr.personal.view']);
    $this->put($this->workspaceUrl('acme', "employees/{$this->employee->id}/personal"), ['national_id' => 'X'])->assertForbidden();

    expect($this->asTenant('acme', fn () => DB::table('employee_personal_details')->count()))->toBe(0);
});
