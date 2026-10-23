<?php

use App\Modules\Employees\Models\Employee;
use App\Modules\Leave\Enums\LeaveStatus;
use App\Modules\Leave\Models\LeaveEntitlement;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use Inertia\Testing\AssertableInertia as Assert;

const LEAVE_ALL = ['hr.employees.view', 'hr.employees.manage', 'hr.leave.view', 'hr.leave.manage', 'hr.leave.approve', 'hr.leave.settings'];

beforeEach(function () {
    // Monday 2 March 2026.
    $this->travelTo('2026-03-02 09:00');
    $this->juba = $this->platform()->branch('acme', 'Juba', 'JUB');
    $this->wau = $this->platform()->branch('acme', 'Wau', 'WAU');

    // HR records requests; someone else decides.
    $this->clerk = $this->platformUser(['name' => 'Clerk']);
    $this->approver = $this->platformUser(['name' => 'Approver']);

    $this->actingAsMember('acme', LEAVE_ALL, user: $this->clerk);
    $this->post($this->workspaceUrl('acme', 'leave/types'), ['name' => 'Annual leave', 'days_per_year' => 5, 'paid' => '1', 'max_carry_over' => 2])->assertSessionHasNoErrors();
    $this->post($this->workspaceUrl('acme', 'leave/types'), ['name' => 'Unpaid leave', 'days_per_year' => 0, 'allow_negative' => '1', 'max_carry_over' => 0])->assertSessionHasNoErrors();
    foreach ([['Akol', 'akol@acme.test', $this->juba], ['Nyandeng', 'nyandeng@acme.test', $this->wau]] as [$first, $email, $branch]) {
        $this->post($this->workspaceUrl('acme', 'employees'), [
            'first_name' => $first, 'last_name' => 'Deng', 'work_email' => $email, 'branch_id' => $branch,
            'hired_on' => '2025-01-06', 'contract_type' => 'permanent',
        ])->assertSessionHasNoErrors();
    }

    [$this->annual, $this->unpaid, $this->akol, $this->nyandeng] = $this->asTenant('acme', fn () => [
        LeaveType::query()->where('name', 'Annual leave')->sole(),
        LeaveType::query()->where('name', 'Unpaid leave')->sole(),
        Employee::query()->where('first_name', 'Akol')->sole(),
        Employee::query()->where('first_name', 'Nyandeng')->sole(),
    ]);
});

function requestLeave(Employee $employee, LeaveType $type, string $from, string $to)
{
    return test()->post(test()->workspaceUrl('acme', 'leave'), [
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'starts_on' => $from,
        'ends_on' => $to,
    ]);
}

function latestLeave(): LeaveRequest
{
    return test()->asTenant('acme', fn () => LeaveRequest::query()->latest('created_at')->latest('id')->firstOrFail());
}

it('counts working days: no weekends, no public holidays', function () {
    $this->post($this->workspaceUrl('acme', 'leave/holidays'), ['date' => '2026-03-11', 'name' => 'Holiday'])->assertSessionHasNoErrors();
    $this->post($this->workspaceUrl('acme', 'leave/holidays'), ['date' => '2026-03-11', 'name' => 'Twice'])->assertSessionHasErrors('date');

    // Friday 6 to Thursday 12 March: Fri, Mon, Tue, Thu.
    requestLeave($this->akol, $this->annual, '2026-03-06', '2026-03-12')->assertSessionHasNoErrors();
    expect(latestLeave())->days->toBe(4)->status->toBe(LeaveStatus::Pending);

    requestLeave($this->nyandeng, $this->annual, '2026-03-07', '2026-03-08')->assertSessionHasErrors('starts_on');
    requestLeave($this->nyandeng, $this->annual, '2026-12-30', '2027-01-02')->assertSessionHasErrors('ends_on');
});

it('never takes a balance below zero unless the kind allows it', function () {
    requestLeave($this->akol, $this->annual, '2026-03-09', '2026-03-12')->assertSessionHasNoErrors(); // 4 days, pending

    // Pending days are held: 1 of 5 is left to ask for.
    requestLeave($this->akol, $this->annual, '2026-03-16', '2026-03-17')->assertSessionHasErrors(['ends_on' => 'This needs 2 days, but only 1 day of Annual leave is left for 2026.']);
    requestLeave($this->akol, $this->annual, '2026-03-16', '2026-03-16')->assertSessionHasNoErrors();

    // Unpaid leave has no days and may go below zero.
    requestLeave($this->akol, $this->unpaid, '2026-04-06', '2026-04-10')->assertSessionHasNoErrors();

    $this->actingAsMember('acme', ['hr.employees.view', 'hr.leave.view']);
    $this->get($this->workspaceUrl('acme', "employees/{$this->akol->id}/leave-balances"))
        ->assertInertia(fn (Assert $page) => $page
            ->component('employees/leave')
            ->where('balances.0.type', 'Annual leave')
            ->where('balances.0.days', 5)
            ->where('balances.0.pending', 5)
            ->where('balances.0.available', 0)
            ->where('balances.1.type', 'Unpaid leave')
            ->where('balances.1.available', -5)
            ->has('requests', 3)
            ->where('canManage', false));
});

it('checks the balance again on approval', function () {
    requestLeave($this->akol, $this->annual, '2026-03-09', '2026-03-12')->assertSessionHasNoErrors();
    $leave = latestLeave();

    // HR lowers the year's days after the request was made.
    $this->put($this->workspaceUrl('acme', "employees/{$this->akol->id}/leave-balances/{$this->annual->id}"), ['year' => 2026, 'days' => 3])->assertSessionHasNoErrors();

    $this->actingAsMember('acme', ['hr.leave.view', 'hr.leave.approve'], user: $this->approver);
    $this->post($this->workspaceUrl('acme', "leave/{$leave->id}/approve"))
        ->assertSessionHasErrors(['decision' => 'This needs 4 days, but only 3 days of Annual leave are left for 2026.']);
    expect(latestLeave()->status)->toBe(LeaveStatus::Pending);
});

it('refuses decisions by whoever recorded the request or the person it is for', function () {
    requestLeave($this->akol, $this->annual, '2026-03-09', '2026-03-10')->assertSessionHasNoErrors();
    $leave = latestLeave();

    // The clerk recorded it.
    $this->post($this->workspaceUrl('acme', "leave/{$leave->id}/approve"))
        ->assertSessionHasErrors(['decision' => 'Someone other than the person who recorded this request must decide on it.']);

    // Akol signs in with his work address: his own leave.
    $akol = $this->platformUser(['name' => 'Akol', 'email' => 'AKOL@acme.test']);
    $this->actingAsMember('acme', ['hr.leave.view', 'hr.leave.approve'], user: $akol);
    $this->post($this->workspaceUrl('acme', "leave/{$leave->id}/reject"))
        ->assertSessionHasErrors(['decision' => 'You cannot decide on your own leave.']);
    $this->get($this->workspaceUrl('acme', 'leave'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('requests.0.canDecide', false)
            ->where('requests.0.decideBlocked', 'You cannot decide on your own leave.'));

    $this->actingAsMember('acme', ['hr.leave.view', 'hr.leave.approve'], user: $this->approver);
    $this->get($this->workspaceUrl('acme', 'leave'))
        ->assertInertia(fn (Assert $page) => $page->where('requests.0.canDecide', true)->where('requests.0.canCancel', false));
    $this->post($this->workspaceUrl('acme', "leave/{$leave->id}/approve"), ['note' => 'Enjoy'])->assertSessionHasNoErrors();

    expect(latestLeave())
        ->status->toBe(LeaveStatus::Approved)
        ->decided_by->toBe($this->approver->id)
        ->decision_note->toBe('Enjoy');

    // Decided once.
    $this->post($this->workspaceUrl('acme', "leave/{$leave->id}/reject"))->assertSessionHasErrors('decision');
});

it('lets a branch approver decide only for their branch', function () {
    requestLeave($this->nyandeng, $this->annual, '2026-03-09', '2026-03-10')->assertSessionHasNoErrors();
    $leave = latestLeave();

    $this->actingAsMember('acme', ['hr.leave.view', 'hr.leave.approve'], user: $this->approver, branch: $this->juba);
    $this->post($this->workspaceUrl('acme', "leave/{$leave->id}/approve"))->assertNotFound();
    $this->get($this->workspaceUrl('acme', 'leave'))->assertInertia(fn (Assert $page) => $page->has('requests', 0));

    // Line managers may decide but not record.
    requestLeave($this->akol, $this->annual, '2026-03-09', '2026-03-10')->assertForbidden();
    expect(latestLeave()->status)->toBe(LeaveStatus::Pending);
});

it('refuses overlapping leave and frees the days on rejection', function () {
    requestLeave($this->akol, $this->annual, '2026-03-09', '2026-03-13')->assertSessionHasNoErrors();
    $leave = latestLeave();
    requestLeave($this->akol, $this->unpaid, '2026-03-13', '2026-03-16')->assertSessionHasErrors(['starts_on' => 'Akol Deng already has leave in those dates.']);

    $this->actingAsMember('acme', ['hr.leave.view', 'hr.leave.approve'], user: $this->approver);
    $this->post($this->workspaceUrl('acme', "leave/{$leave->id}/reject"), ['note' => 'Stock count that week'])->assertSessionHasNoErrors();

    $this->actingAsMember('acme', LEAVE_ALL, user: $this->clerk);
    requestLeave($this->akol, $this->annual, '2026-03-13', '2026-03-16')->assertSessionHasNoErrors();
});

it('cancels approved leave only before it starts, giving the days back', function () {
    requestLeave($this->akol, $this->annual, '2026-03-09', '2026-03-10')->assertSessionHasNoErrors();
    $leave = latestLeave();
    $this->actingAsMember('acme', ['hr.leave.view', 'hr.leave.approve'], user: $this->approver);
    $this->post($this->workspaceUrl('acme', "leave/{$leave->id}/approve"))->assertSessionHasNoErrors();

    // The approver neither recorded it nor manages leave.
    $this->post($this->workspaceUrl('acme', "leave/{$leave->id}/cancel"))->assertForbidden();

    $this->actingAsMember('acme', LEAVE_ALL, user: $this->clerk);
    $this->travelTo('2026-03-09 08:00');
    $this->post($this->workspaceUrl('acme', "leave/{$leave->id}/cancel"))->assertSessionHasErrors(['decision' => 'This leave has started: it can no longer be cancelled.']);

    $this->travelTo('2026-03-06 08:00');
    $this->post($this->workspaceUrl('acme', "leave/{$leave->id}/cancel"))->assertSessionHasNoErrors();
    expect(latestLeave())->status->toBe(LeaveStatus::Cancelled)->cancelled_by->toBe($this->clerk->id);
    requestLeave($this->akol, $this->annual, '2026-03-16', '2026-03-20')->assertSessionHasNoErrors();
});

it('carries unused days into the next year, up to the limit', function () {
    // 2025: 5 days, 1 taken, 4 unused; the limit is 2.
    $this->travelTo('2025-06-02 09:00');
    requestLeave($this->akol, $this->annual, '2025-06-09', '2025-06-09')->assertSessionHasNoErrors();
    $this->actingAsMember('acme', ['hr.leave.view', 'hr.leave.approve'], user: $this->approver);
    $this->post($this->workspaceUrl('acme', 'leave/'.latestLeave()->id.'/approve'))->assertSessionHasNoErrors();

    $this->travelTo('2026-03-02 09:00');
    $this->actingAsMember('acme', LEAVE_ALL, user: $this->clerk);
    requestLeave($this->akol, $this->annual, '2026-03-09', '2026-03-13')->assertSessionHasNoErrors();

    $this->asTenant('acme', function () {
        $entitlement = LeaveEntitlement::query()->where('year', 2026)->where('employee_id', $this->akol->id)->sole();
        expect($entitlement->days)->toBe(5)->and($entitlement->carried_days)->toBe(2);
    });

    // 7 in all, 5 asked for: 2 left.
    requestLeave($this->akol, $this->annual, '2026-03-16', '2026-03-18')->assertSessionHasErrors('ends_on');
    requestLeave($this->akol, $this->annual, '2026-03-16', '2026-03-17')->assertSessionHasNoErrors();
});

it('cancels waiting and later leave when someone leaves', function () {
    requestLeave($this->akol, $this->annual, '2026-03-09', '2026-03-10')->assertSessionHasNoErrors();
    requestLeave($this->akol, $this->annual, '2026-04-06', '2026-04-06')->assertSessionHasNoErrors();

    $this->actingAsMember('acme', ['hr.employees.view', 'hr.employment.manage', 'hr.leave.view', 'hr.leave.manage'], user: $this->approver);
    $this->post($this->workspaceUrl('acme', "employees/{$this->akol->id}/leave"), ['left_on' => '2026-03-31', 'reason' => 'resigned'])->assertSessionHasNoErrors();

    $this->asTenant('acme', function () {
        expect(LeaveRequest::query()->where('status', LeaveStatus::Cancelled->value)->count())->toBe(2);
    });

    $this->actingAsMember('acme', LEAVE_ALL, user: $this->clerk);
    requestLeave($this->akol, $this->unpaid, '2026-03-16', '2026-03-16')->assertSessionHasErrors('employee_id');
});

it('keeps kinds of leave and holidays to members with the settings permission', function () {
    $this->actingAsMember('acme', ['hr.leave.view', 'hr.leave.manage', 'hr.leave.approve'], user: $this->approver);
    $this->get($this->workspaceUrl('acme', 'leave/settings'))->assertForbidden();
    $this->post($this->workspaceUrl('acme', 'leave/types'), ['name' => 'Study', 'days_per_year' => 5, 'max_carry_over' => 0])->assertForbidden();

    $this->actingAsMember('acme', LEAVE_ALL, user: $this->clerk);
    $this->post($this->workspaceUrl('acme', 'leave/types'), ['name' => 'annual LEAVE', 'days_per_year' => 5, 'max_carry_over' => 0])->assertSessionHasErrors('name');

    // An archived kind takes no new requests and keeps its history.
    $this->put($this->workspaceUrl('acme', "leave/types/{$this->unpaid->id}"), ['name' => 'Unpaid leave', 'days_per_year' => 0, 'allow_negative' => '1', 'max_carry_over' => 0, 'archived' => '1'])->assertSessionHasNoErrors();
    requestLeave($this->akol, $this->unpaid, '2026-03-09', '2026-03-09')->assertSessionHasErrors('leave_type_id');

    $this->get($this->workspaceUrl('acme', 'leave/settings'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('leave/settings')
            ->where('types.0.name', 'Annual leave')
            ->where('types.1.archived', true));
});

it('shows who is away today and what waits on the home page', function () {
    requestLeave($this->akol, $this->annual, '2026-03-02', '2026-03-03')->assertSessionHasNoErrors();
    $this->actingAsMember('acme', ['hr.employees.view', 'hr.leave.view', 'hr.leave.approve'], user: $this->approver);
    $this->post($this->workspaceUrl('acme', 'leave/'.latestLeave()->id.'/approve'))->assertSessionHasNoErrors();

    $this->actingAsMember('acme', LEAVE_ALL, user: $this->clerk);
    requestLeave($this->nyandeng, $this->annual, '2026-03-09', '2026-03-09')->assertSessionHasNoErrors();

    $this->get($this->workspaceUrl('acme'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.leave.awayToday', 1)
            ->where('summary.leave.waiting', 1));
    $this->get($this->workspaceUrl('acme', 'leave'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('leave/index')
            ->has('away', 1)
            ->where('away.0.name', 'Akol Deng')
            ->has('requests', 1)
            ->has('options.employees', 2));
});
