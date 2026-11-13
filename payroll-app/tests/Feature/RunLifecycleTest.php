<?php

use App\Models\AuditEvent;
use App\Models\PayrollPayment;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo('2026-03-20 09:00');
    $this->officer = $this->platformUser(['name' => 'Officer']);
    $this->reviewer = $this->platformUser(['name' => 'Reviewer']);
    $this->approver = $this->platformUser(['name' => 'Approver']);
    $this->actingAsMember('acme', PAYROLL_ALL, user: $this->officer);
    setUpPayrollWithRules();
});

function lifecycleRun(): PayrollRun
{
    return test()->asTenant('acme', fn () => PayrollRun::query()->where('status', '!=', PayrollRun::REVERSED)->sole());
}

function preparedAndApproved(): PayrollRun
{
    test()->actingAsMember('acme', PAYROLL_ALL, user: test()->officer);
    test()->post(test()->workspaceUrl('acme', 'runs'), ['month' => '2026-03', 'pay_date' => '2026-03-28'])->assertSessionHasNoErrors();
    $run = lifecycleRun();
    test()->actingAsMember('acme', ['payroll.pay.view', 'payroll.runs.approve', 'payroll.runs.pay'], user: test()->approver);
    test()->post(test()->workspaceUrl('acme', "runs/{$run->id}/approve"))->assertSessionHasNoErrors();

    return lifecycleRun();
}

/**
 * @return list<object>
 */
function financeEvents(): array
{
    return DB::table('platform_outbox')->orderBy('created_at')->orderBy('id')->get()->all();
}

it('keeps the currency the run was prepared in', function () {
    addEmployee('Akol');
    $run = preparedAndApproved();

    expect($run->currency)->toBe('SSP');

    // The organization changes currency later: the run and its events do not.
    $this->asTenant('acme', fn () => Workspace::current()->forceFill(['currency' => 'USD'])->save());
    $this->post($this->workspaceUrl('acme', "runs/{$run->id}/pay"), ['method' => 'cash', 'paid_on' => '2026-03-28'])->assertSessionHasNoErrors();

    expect(array_map(fn ($row) => json_decode($row->payload, true)['currency'], financeEvents()))->toBe(['SSP', 'SSP']);
});

it('lets someone other than the preparer review, and a recalculation clears the review', function () {
    addEmployee('Akol');
    $this->post($this->workspaceUrl('acme', 'runs'), ['month' => '2026-03', 'pay_date' => '2026-03-28'])->assertSessionHasNoErrors();
    $run = lifecycleRun();

    $this->post($this->workspaceUrl('acme', "runs/{$run->id}/review"))->assertSessionHasErrors(['run' => 'Someone other than whoever prepared this payroll must review it.']);

    $this->actingAsMember('acme', ['payroll.pay.view', 'payroll.runs.review'], user: $this->reviewer);
    $this->post($this->workspaceUrl('acme', "runs/{$run->id}/review"))->assertSessionHasNoErrors();
    expect(lifecycleRun())->status->toBe(PayrollRun::REVIEWED)->reviewed_by->toBe($this->reviewer->id);
    // Reviewing is not approving.
    $this->post($this->workspaceUrl('acme', "runs/{$run->id}/approve"))->assertForbidden();

    $this->actingAsMember('acme', PAYROLL_ALL, user: $this->officer);
    $this->post($this->workspaceUrl('acme', "runs/{$run->id}/recalculate"))->assertSessionHasNoErrors();
    expect(lifecycleRun())->status->toBe(PayrollRun::DRAFT)->reviewed_by->toBeNull();

    // A reviewed run is approved like a draft one.
    $this->actingAsMember('acme', ['payroll.pay.view', 'payroll.runs.review'], user: $this->reviewer);
    $this->post($this->workspaceUrl('acme', "runs/{$run->id}/review"))->assertSessionHasNoErrors();
    $this->actingAsMember('acme', ['payroll.pay.view', 'payroll.runs.approve'], user: $this->approver);
    $this->post($this->workspaceUrl('acme', "runs/{$run->id}/approve"))->assertSessionHasNoErrors();
    expect(lifecycleRun()->status)->toBe(PayrollRun::APPROVED);
});

it('pays some payslips first and the rest later, one Finance payment per batch', function () {
    addEmployee('Akol');
    addEmployee('Nyandeng', salary: '50000');
    $run = preparedAndApproved();
    [$akol, $nyandeng] = $this->asTenant('acme', fn () => Payslip::query()->orderBy('employee_number')->get()->all());

    $this->post($this->workspaceUrl('acme', "runs/{$run->id}/pay"), ['method' => 'bank', 'paid_on' => '2026-03-27', 'reference' => 'BATCH-77', 'payslips' => [$akol->id]])->assertSessionHasNoErrors();
    expect(lifecycleRun())->status->toBe(PayrollRun::PART_PAID)->paid_minor->toBe($akol->net_minor);

    // Paid once only.
    $this->post($this->workspaceUrl('acme', "runs/{$run->id}/pay"), ['method' => 'bank', 'paid_on' => '2026-03-27', 'payslips' => [$akol->id]])->assertSessionHasErrors('payslips');
    // A reversal is no longer possible once pay went out.
    $this->post($this->workspaceUrl('acme', "runs/{$run->id}/reverse"), ['reason' => 'Wrong month'])->assertSessionHasErrors('run');

    // Everyone not paid yet.
    $this->post($this->workspaceUrl('acme', "runs/{$run->id}/pay"), ['method' => 'mobile_money', 'paid_on' => '2026-03-28'])->assertSessionHasNoErrors();
    $paid = lifecycleRun();
    expect($paid)->status->toBe(PayrollRun::PAID)->paid_minor->toBe($run->net_minor)->payment_method->toBe('mobile_money');

    $payments = $this->asTenant('acme', fn () => PayrollPayment::query()->orderBy('number')->get());
    expect($payments->pluck('number')->all())->toBe(['PR-00001-P1', 'PR-00001-P2'])
        ->and($payments->pluck('headcount')->all())->toBe([1, 1])
        ->and($payments->first()->reference)->toBe('BATCH-77');

    $events = array_slice(financeEvents(), 1);
    expect(array_column($events, 'event_type'))->toBe(['finance.payroll.paid', 'finance.payroll.paid'])
        ->and(array_column($events, 'source_transaction_id'))->toBe(['payroll:PR-00001-P1:paid', 'payroll:PR-00001-P2:paid'])
        ->and(array_map(fn ($row) => json_decode($row->payload, true)['amount_minor'], $events))->toBe([$akol->net_minor, $nyandeng->net_minor]);

    $this->post($this->workspaceUrl('acme', "runs/{$run->id}/pay"), ['method' => 'cash', 'paid_on' => '2026-03-28'])->assertSessionHasErrors('run');
});

it('downloads payment instructions with account details for the payslips not paid yet', function () {
    $akol = addEmployee('Akol');
    addEmployee('Nyandeng', salary: '50000');
    $this->put($this->workspaceUrl('acme', "employees/{$akol->id}/payment-details"), ['method' => 'bank', 'bank_name' => 'Ivory Bank', 'account_name' => 'Akol Deng', 'account_number' => '0012345'])->assertSessionHasNoErrors();
    $run = preparedAndApproved();

    $text = xlsxText($this->get($this->workspaceUrl('acme', "runs/{$run->id}?export=instructions"))->assertOk()->streamedContent());
    expect($text)->toContain('Ivory Bank')->toContain('0012345')->toContain('Nyandeng Deng')->toContain('Not set');

    $akolSlip = $this->asTenant('acme', fn () => Payslip::query()->where('employee_id', $akol->id)->sole());
    $this->post($this->workspaceUrl('acme', "runs/{$run->id}/pay"), ['method' => 'bank', 'paid_on' => '2026-03-28', 'payslips' => [$akolSlip->id]])->assertSessionHasNoErrors();

    expect(xlsxText($this->get($this->workspaceUrl('acme', "runs/{$run->id}?export=instructions"))->streamedContent()))->not->toContain('0012345');
    $batch = $this->asTenant('acme', fn () => PayrollPayment::query()->sole());
    expect(xlsxText($this->get($this->workspaceUrl('acme', "runs/{$run->id}?export=instructions&payment={$batch->id}"))->streamedContent()))
        ->toContain('0012345')->not->toContain('Nyandeng');
});

it('reverses an approved run nobody was paid from, and the month can be prepared again', function () {
    addEmployee('Akol');
    $run = preparedAndApproved();

    $this->post($this->workspaceUrl('acme', "runs/{$run->id}/reverse"), [])->assertSessionHasErrors('reason');
    $this->post($this->workspaceUrl('acme', "runs/{$run->id}/reverse"), ['reason' => 'Prepared before the March pay rise'])->assertSessionHasNoErrors();

    $reversed = $this->asTenant('acme', fn () => PayrollRun::query()->whereKey($run->id)->sole());
    expect($reversed)->status->toBe(PayrollRun::REVERSED)->reversed_by->toBe($this->approver->id)->reversal_reason->toBe('Prepared before the March pay rise');

    $event = financeEvents()[1];
    expect($event->event_type)->toBe('finance.payroll.reversed')
        ->and(json_decode($event->payload, true))->toBe(['run_reference' => 'PR-00001', 'reversed_on' => '2026-03-20', 'reason' => 'Prepared before the March pay rise']);

    // Nothing more happens to it.
    $this->post($this->workspaceUrl('acme', "runs/{$run->id}/pay"), ['method' => 'cash', 'paid_on' => '2026-03-28'])->assertSessionHasErrors('run');
    $this->post($this->workspaceUrl('acme', "runs/{$run->id}/reverse"), ['reason' => 'Again'])->assertSessionHasErrors('run');

    $this->actingAsMember('acme', PAYROLL_ALL, user: $this->officer);
    $this->post($this->workspaceUrl('acme', 'runs'), ['month' => '2026-03', 'pay_date' => '2026-03-28'])->assertSessionHasNoErrors();
    expect(lifecycleRun())->number->toBe('PR-00002')->status->toBe(PayrollRun::DRAFT);
});

it('keeps an audit trail of runs, pay and settings, shown to those allowed', function () {
    $akol = addEmployee('Akol');
    $this->put($this->workspaceUrl('acme', "employees/{$akol->id}/payment-details"), ['method' => 'mobile_money', 'mobile_number' => '+211912345678'])->assertSessionHasNoErrors();
    $run = preparedAndApproved();
    $this->post($this->workspaceUrl('acme', "runs/{$run->id}/pay"), ['method' => 'cash', 'paid_on' => '2026-03-28'])->assertSessionHasNoErrors();

    $actions = $this->asTenant('acme', fn () => AuditEvent::query()->orderBy('created_at')->orderBy('id')->pluck('action')->all());
    expect($actions)->toContain('settings.rule_added', 'employee.added', 'employee.pay_set', 'employee.payment_details_changed', 'run.prepared', 'run.approved', 'run.paid');

    $details = $this->asTenant('acme', fn () => AuditEvent::query()->where('action', 'employee.payment_details_changed')->sole()->details);
    // Never the account itself.
    expect($details)->toBe(['method' => 'mobile_money']);

    $approved = $this->asTenant('acme', fn () => AuditEvent::query()->where('action', 'run.approved')->sole());
    expect($approved)->user_name->toBe('Approver')->subject_label->toBe('PR-00001 (March 2026)');

    $this->actingAsMember('acme', ['payroll.pay.view', 'payroll.audit.view'], user: $this->platformUser(['name' => 'Auditor']));
    $this->get($this->workspaceUrl('acme', 'audit?subject=run'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('audit/index')->has('events.data', 3)->where('events.data.0.action', 'run.paid'));

    $this->actingAsMember('acme', ['payroll.pay.view'], user: $this->approver);
    $this->get($this->workspaceUrl('acme', 'audit'))->assertForbidden();
});

it('refuses to change or delete the audit trail in the database itself', function () {
    addEmployee('Akol');

    expect(fn () => DB::table('payroll_audit_events')->update(['action' => 'x']))->toThrow(QueryException::class, 'append-only')
        ->and(fn () => DB::table('payroll_audit_events')->delete())->toThrow(QueryException::class, 'append-only');
})->skip(fn () => DB::getDriverName() !== 'pgsql', 'PostgreSQL only');
