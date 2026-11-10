<?php

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\Journal;
use App\Models\JournalDraft;
use App\Models\PaymentRequest;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The audit trail: control events written with the change, with who did it
 * and what changed, never altered, and kept within the organization.
 */
beforeEach(function () {
    $this->platform()->organization('acme');
    $this->setUpFinance('acme');
    $this->travelTo('2026-10-20 09:00');
    $this->people = new ArrayObject;
});

function auditUrl(string $path = ''): string
{
    return test()->workspaceUrl('acme', $path);
}

function auditAccount(string $code): string
{
    return test()->asTenant('acme', fn () => Account::query()->where('code', $code)->sole()->id);
}

/**
 * @param  list<string>  $permissions
 */
function auditAs(string $name, array $permissions): void
{
    $people = test()->people;
    if (! isset($people[$name])) {
        $people[$name] = test()->platformUser(['name' => $name]);
    }
    test()->actingAsMember('acme', $permissions, user: $people[$name]);
}

/**
 * @return list<array{action: string, by: string|null, before: array<string, mixed>|null, after: array<string, mixed>|null}>
 */
function auditEvents(string $prefix = ''): array
{
    return test()->asTenant('acme', fn () => AuditEvent::query()->with('actor')->where('action', 'like', "{$prefix}%")->orderBy('created_at')->orderBy('id')->get()
        ->map(fn (AuditEvent $event) => ['action' => $event->action, 'by' => $event->actor?->name, 'before' => $event->before, 'after' => $event->after])->all());
}

function auditLatestRequest(): PaymentRequest
{
    return test()->asTenant('acme', fn () => PaymentRequest::query()->orderByDesc('number')->firstOrFail());
}

it('records each step of a payment, with who took it and what was approved and paid', function () {
    auditAs('Officer', ['finance.payments.view', 'finance.payments.prepare']);
    $this->post(auditUrl('payment-requests'), [
        'kind' => 'expense', 'account_id' => auditAccount('1010'), 'other_account_id' => auditAccount('6000'),
        'amount' => '300', 'pay_on' => '2026-10-05', 'description' => 'October rent',
    ])->assertSessionHasNoErrors();
    $request = auditLatestRequest();

    auditAs('Manager', ['finance.payments.view', 'finance.payments.approve']);
    $this->post(auditUrl("payment-requests/{$request->id}/approve"))->assertSessionHasNoErrors();
    auditAs('Cashier', ['finance.payments.view', 'finance.payments.disburse']);
    $this->post(auditUrl("payment-requests/{$request->id}/disburse"), ['paid_on' => '2026-10-20'])->assertSessionHasNoErrors();

    $events = auditEvents('payment_request.');
    expect(array_column($events, 'action'))->toBe(['payment_request.prepared', 'payment_request.approved', 'payment_request.disbursed'])
        ->and(array_column($events, 'by'))->toBe(['Officer', 'Manager', 'Cashier'])
        ->and($events[1]['after']['amount_minor'])->toBe(30000)
        ->and($events[1]['after']['account_id'])->toBe(auditAccount('1010'))
        ->and($events[2]['before'])->toBe(['status' => PaymentRequest::APPROVED])
        ->and($events[2]['after']['journal_id'])->toBe(auditLatestRequest()->journal_id);
});

it('records rejections and withdrawals with the reason', function () {
    auditAs('Officer', ['finance.payments.view', 'finance.payments.prepare']);
    $raise = fn () => $this->post(auditUrl('payment-requests'), [
        'kind' => 'expense', 'account_id' => auditAccount('1010'), 'other_account_id' => auditAccount('6000'),
        'amount' => '10', 'pay_on' => '2026-10-05', 'description' => 'Tea',
    ])->assertSessionHasNoErrors();
    $raise();
    $first = auditLatestRequest();
    $raise();
    $second = auditLatestRequest();
    $this->post(auditUrl("payment-requests/{$second->id}/withdraw"))->assertSessionHasNoErrors();

    auditAs('Manager', ['finance.payments.view', 'finance.payments.approve']);
    $this->post(auditUrl("payment-requests/{$first->id}/reject"), ['reason' => 'Not budgeted'])->assertSessionHasNoErrors();

    $closed = array_values(array_filter(auditEvents('payment_request.'), fn (array $event) => $event['action'] !== 'payment_request.prepared'));
    expect(array_column($closed, 'action'))->toBe(['payment_request.withdrawn', 'payment_request.rejected'])
        ->and($closed[1]['after'])->toBe(['status' => PaymentRequest::REJECTED, 'note' => 'Not budgeted'])
        ->and($closed[1]['by'])->toBe('Manager');
});

it('keeps the rules in force with each request and held journal', function () {
    auditAs('Admin', ['finance.approvals.configure']);
    $this->put(auditUrl('payment-requests/settings'), ['threshold' => '50', 'payment_review' => '1'])->assertSessionHasNoErrors();
    $this->put(auditUrl('journal-approvals/settings'), ['journal_approval' => '1', 'threshold' => null])->assertSessionHasNoErrors();

    auditAs('Officer', ['finance.payments.view', 'finance.payments.prepare']);
    $this->post(auditUrl('payment-requests'), [
        'kind' => 'expense', 'account_id' => auditAccount('1010'), 'other_account_id' => auditAccount('6000'),
        'amount' => '60', 'pay_on' => '2026-10-05', 'description' => 'Supplies',
    ])->assertSessionHasNoErrors();
    auditAs('Accountant', ['finance.accounts.view', 'finance.journals.view', 'finance.journals.prepare']);
    $this->post(auditUrl('journals'), ['entry_date' => '2026-10-05', 'memo' => 'Accrual', 'lines' => [
        ['account_id' => auditAccount('6000'), 'debit' => '10'], ['account_id' => auditAccount('2000'), 'credit' => '10'],
    ]])->assertSessionHasNoErrors();

    $rules = ['payment_threshold_minor' => 5000, 'payment_review' => true, 'journal_approval' => true, 'journal_threshold_minor' => null];
    expect(auditLatestRequest()->policy)->toBe($rules)
        ->and($this->asTenant('acme', fn () => JournalDraft::query()->sole()->policy))->toBe($rules);

    // Changing the rules later leaves what the request was raised under.
    auditAs('Admin', ['finance.approvals.configure']);
    $this->put(auditUrl('payment-requests/settings'), ['threshold' => null, 'payment_review' => '0'])->assertSessionHasNoErrors();
    expect(auditLatestRequest()->policy)->toBe($rules)
        ->and(array_column(auditEvents('approval_rules.'), 'action'))->toBe(['approval_rules.payments_changed', 'approval_rules.journals_changed', 'approval_rules.payments_changed'])
        ->and(auditEvents('approval_rules.')[2]['before'])->toBe(['threshold_minor' => 5000, 'review' => true]);
});

it('records journal approval, posting and reversal', function () {
    $this->paymentLimit('acme', 1_000_00);
    auditAs('Admin', ['finance.approvals.configure']);
    $this->put(auditUrl('journal-approvals/settings'), ['journal_approval' => '1', 'threshold' => '500'])->assertSessionHasNoErrors();

    auditAs('Accountant', ['finance.accounts.view', 'finance.journals.view', 'finance.journals.post']);
    $this->post(auditUrl('journals'), ['entry_date' => '2026-10-05', 'memo' => 'Small', 'lines' => [
        ['account_id' => auditAccount('6000'), 'debit' => '10'], ['account_id' => auditAccount('1010'), 'credit' => '10'],
    ]])->assertSessionHasNoErrors();
    $this->post(auditUrl('journals'), ['entry_date' => '2026-10-05', 'memo' => 'Large', 'lines' => [
        ['account_id' => auditAccount('6000'), 'debit' => '600'], ['account_id' => auditAccount('2000'), 'credit' => '600'],
    ]])->assertSessionHasNoErrors();
    $draft = $this->asTenant('acme', fn () => JournalDraft::query()->sole());

    auditAs('Chief', ['finance.accounts.view', 'finance.journals.view', 'finance.journals.approve', 'finance.journals.reverse']);
    $this->post(auditUrl("journal-approvals/{$draft->id}/approve"))->assertSessionHasNoErrors();
    $small = $this->asTenant('acme', fn () => Journal::query()->where('memo', 'Small')->sole());
    $this->post(auditUrl("journals/{$small->id}/reverse"), ['entry_date' => '2026-10-06', 'reason' => 'Duplicate'])->assertSessionHasNoErrors();

    $events = array_values(array_filter(auditEvents(), fn (array $event) => ! str_starts_with($event['action'], 'approval_rules.')));
    expect(array_column($events, 'action'))->toBe(['journal.posted', 'journal_draft.submitted', 'journal_draft.approved', 'journal.reversed'])
        ->and(array_column($events, 'by'))->toBe(['Accountant', 'Accountant', 'Chief', 'Chief'])
        ->and($events[3]['after']['reason'])->toBe('Duplicate');
});

it('records locking, unlocking, closing and reopening the books, and keeps who unlocked', function () {
    auditAs('Chief', ['finance.journals.view', 'finance.periods.manage']);
    $this->post(auditUrl('periods/years'), ['starts_on' => '2025-01-01', 'months' => 12])->assertSessionHasNoErrors();
    $year = $this->asTenant('acme', fn () => FiscalYear::query()->sole());
    $months = $this->asTenant('acme', fn () => FiscalPeriod::query()->orderBy('starts_on')->get());
    $this->post(auditUrl("periods/{$months[0]->id}/lock"))->assertSessionHasNoErrors();
    $this->post(auditUrl("periods/{$months[0]->id}/lock"))->assertSessionHasNoErrors(); // already: nothing new
    $this->post(auditUrl("periods/{$months[0]->id}/unlock"))->assertSessionHasNoErrors();
    $this->post(auditUrl("periods/years/{$year->id}/close"))->assertSessionHasNoErrors();
    $this->post(auditUrl("periods/years/{$year->id}/reopen"))->assertSessionHasNoErrors();

    $events = auditEvents('period.');
    expect(array_column($events, 'action'))->toBe(['period.year_added', 'period.locked', 'period.unlocked', 'period.year_closed', 'period.year_reopened'])
        ->and($events[2]['before']['locked_by'])->toBe((string) $this->people['Chief']->getAuthIdentifier())
        ->and($events[2]['by'])->toBe('Chief');
});

it('records what changed on an account, bank details included', function () {
    auditAs('Chief', ['finance.accounts.view', 'finance.accounts.manage', 'finance.banking.view']);
    $this->post(auditUrl('accounts'), ['code' => '1060', 'name' => 'Payroll account', 'type' => 'asset', 'bank_kind' => 'bank', 'bank_account_number' => '111'])->assertSessionHasNoErrors();
    $id = auditAccount('1060');
    $this->put(auditUrl("accounts/{$id}"), ['code' => '1060', 'name' => 'Payroll account', 'type' => 'asset', 'bank_kind' => 'bank', 'bank_account_number' => '999', 'active' => '1'])->assertSessionHasNoErrors();

    $changed = auditEvents('account.changed')[0];
    expect($changed['before'])->toBe(['bank_account_number' => '111'])
        ->and($changed['after'])->toBe(['bank_account_number' => '999'])
        ->and($changed['by'])->toBe('Chief');
});

it('never changes or deletes an event, and has no route that does', function () {
    auditAs('Admin', ['finance.approvals.configure', 'finance.audit.view']);
    $this->put(auditUrl('payment-requests/settings'), ['threshold' => '10', 'payment_review' => '0'])->assertSessionHasNoErrors();
    $event = $this->asTenant('acme', fn () => AuditEvent::query()->sole());

    expect(fn () => $this->asTenant('acme', fn () => $event->forceFill(['action' => 'nothing'])->save()))->toThrow(LogicException::class)
        ->and(fn () => $this->asTenant('acme', fn () => $event->delete()))->toThrow(LogicException::class);

    $this->delete(auditUrl("audit/{$event->id}"))->assertStatus(404);
    $this->put(auditUrl("audit/{$event->id}"))->assertStatus(404);
    expect($this->asTenant('acme', fn () => AuditEvent::query()->sole()->action))->toBe('approval_rules.payments_changed');
});

it('shows the trail to holders of audit.view only, and only their own organization\'s', function () {
    auditAs('Admin', ['finance.approvals.configure']);
    $this->put(auditUrl('payment-requests/settings'), ['threshold' => '10', 'payment_review' => '0'])->assertSessionHasNoErrors();

    auditAs('Auditor', ['finance.audit.view']);
    $this->get(auditUrl('audit'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('audit/index')
        ->has('events', 1)
        ->where('events.0.action', 'approval_rules.payments_changed')
        ->where('events.0.by', 'Admin'));
    $this->get(auditUrl('audit?area=period'))->assertInertia(fn (Assert $page) => $page->has('events', 0));

    auditAs('Clerk', ['finance.journals.view']);
    $this->get(auditUrl('audit'))->assertForbidden();

    $this->platform()->organization('other');
    $this->setUpFinance('other');
    $this->actingAsMember('other', ['finance.audit.view']);
    $this->get($this->workspaceUrl('other', 'audit'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('events', 0));
});
