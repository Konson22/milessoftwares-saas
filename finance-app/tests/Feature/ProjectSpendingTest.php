<?php

use App\Finance\Ledger;
use App\Finance\ProjectSpending;
use App\Models\Account;
use App\Models\DimensionValue;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\PaymentRequest;
use App\Models\ProjectSpendingRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
| Spending Projects hands to Finance (Phase 3C): received without being
| approved or paid; taken in as an ordinary payment request, or refused, by
| Finance's people; every outcome told back to Projects through the outbox,
| in the same transaction, numbered per request.
*/

beforeEach(function () {
    $this->platform()->organization('acme');
    $this->publishEventKeys();
    $this->setUpFinance('acme');
    // Every payment needs approval: Projects' approval decides nothing here.
    $this->paymentLimit('acme', 1);
    $this->psStaff = [];
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array{response: TestResponse, request_id: string, correlation_id: string}
 */
function psSubmit(array $overrides = [], int $version = 1, ?string $requestId = null, ?string $correlationId = null, string $slug = 'acme', string $source = 'projects'): array
{
    $requestId ??= (string) Str::uuid();
    $correlationId ??= (string) Str::uuid();
    $projectId = '5f0c2f8e-0000-4000-8000-000000000001';

    $response = test()->sendEvent('finance.spending.submitted', "finance-request:{$requestId}:r{$version}:submitted", $overrides + [
        'originating_app' => 'projects',
        'organization_id' => test()->platform()->organization($slug)['id'],
        'project_id' => $projectId,
        'request_id' => $requestId,
        'correlation_id' => $correlationId,
        'idempotency_key' => "finance-request:{$requestId}:r{$version}",
        'version' => $version,
        'contract_version' => 1,
        'currency' => 'SSP',
        'amount_minor' => 120_000,
        'created_at' => '2026-11-17T09:00:00+00:00',
        'status' => 'submitted',
        'project' => ['id' => $projectId, 'code' => 'WASH', 'name' => 'Water for Juba'],
        'expense_id' => (string) Str::uuid(),
        'category' => 'materials',
        'description' => 'Hand pumps',
        'supplier' => 'Pumps Ltd',
        'grant_reference' => null,
        'approvals' => 1,
    ], $slug, source: $source);

    return ['response' => $response, 'request_id' => $requestId, 'correlation_id' => $correlationId];
}

function psCancel(string $requestId, string $correlationId, int $version = 1): TestResponse
{
    return test()->sendEvent('finance.spending.cancelled', "finance-request:{$requestId}:r{$version}:cancelled", [
        'originating_app' => 'projects',
        'organization_id' => test()->platform()->organization('acme')['id'],
        'project_id' => '5f0c2f8e-0000-4000-8000-000000000001',
        'request_id' => $requestId,
        'correlation_id' => $correlationId,
        'idempotency_key' => "finance-request:{$requestId}:r{$version}",
        'version' => $version,
        'contract_version' => 1,
        'currency' => 'SSP',
        'amount_minor' => 120_000,
        'created_at' => '2026-11-17T09:00:00+00:00',
        'status' => 'cancellation_requested',
        'reason' => 'No longer needed',
    ], source: 'projects');
}

/**
 * @param  list<string>  $permissions
 */
function psAs(string $who, array $permissions): void
{
    $staff = test()->psStaff;
    $staff[$who] ??= test()->platformUser(['name' => $who]);
    test()->psStaff = $staff;
    test()->actingAsMember('acme', $permissions, user: $staff[$who]);
}

function psRow(string $requestId): ProjectSpendingRequest
{
    return test()->asTenant('acme', fn () => ProjectSpendingRequest::query()->where('request_id', $requestId)->sole());
}

/**
 * What Finance told Projects, oldest first.
 *
 * @return list<array{type: string, transaction: string, payload: array<string, mixed>}>
 */
function psOutcomes(): array
{
    return DB::table('platform_outbox')->where('audience', 'projects')->orderBy('source_transaction_id')->get()
        ->map(fn ($row) => ['type' => $row->event_type, 'transaction' => $row->source_transaction_id, 'payload' => json_decode($row->payload, true)])
        ->sortBy(fn ($o) => $o['payload']['sequence'])->values()->all();
}

function psBank(): string
{
    return test()->asTenant('acme', fn () => Account::query()->where('system_key', Account::BANK)->sole()->id);
}

function psTakeIn(string $requestId, string $amount = '1200.00'): TestResponse
{
    // Also an approver: Finance's rules, not a missing permission, stop them approving their own.
    psAs('officer', ['finance.payments.view', 'finance.payments.prepare', 'finance.payments.approve']);

    return test()->post(test()->workspaceUrl('acme', 'project-requests/'.psRow($requestId)->id.'/prepare'), ['account_id' => psBank(), 'amount' => $amount, 'pay_on' => '2026-11-17']);
}

function psPayment(string $requestId): PaymentRequest
{
    return test()->asTenant('acme', fn () => PaymentRequest::query()->findOrFail(psRow($requestId)->payment_request_id));
}

it('receives a request without approving, posting or paying anything', function () {
    $sent = psSubmit();
    $sent['response']->assertCreated();

    $row = psRow($sent['request_id']);
    expect($row->status)->toBe(ProjectSpending::RECEIVED)
        ->and($row->amount_minor)->toBe(120_000)
        ->and($row->project_code)->toBe('WASH')
        ->and(test()->asTenant('acme', fn () => PaymentRequest::query()->count()))->toBe(0)
        ->and(test()->asTenant('acme', fn () => Journal::query()->count()))->toBe(0)
        ->and(psOutcomes())->toBe([]);

    // The waiting list shows it to payment preparers.
    psAs('officer', ['finance.payments.view', 'finance.payments.prepare', 'finance.payments.approve']);
    $this->get($this->workspaceUrl('acme', 'project-requests'))->assertOk()
        ->assertInertia(fn ($page) => $page->component('project-requests/index')->has('waiting', 1)->where('waiting.0.amountMajor', '1200.00'));
});

it('takes in a request exactly once, however often it is delivered', function () {
    $requestId = (string) Str::uuid();
    $correlationId = (string) Str::uuid();
    $expense = (string) Str::uuid();

    psSubmit(['expense_id' => $expense], requestId: $requestId, correlationId: $correlationId)['response']->assertCreated();
    psSubmit(['expense_id' => $expense], requestId: $requestId, correlationId: $correlationId)['response']->assertOk()->assertJson(['status' => 'duplicate']);
    psSubmit(['expense_id' => $expense, 'amount_minor' => 999], requestId: $requestId, correlationId: $correlationId)['response']->assertStatus(409);

    expect(test()->asTenant('acme', fn () => ProjectSpendingRequest::query()->count()))->toBe(1);
});

it('refuses requests that are malformed, for someone else, or not from Projects', function () {
    psSubmit(['organization_id' => (string) Str::uuid()])['response']->assertStatus(422);
    psSubmit(['idempotency_key' => 'finance-request:nope:r1'])['response']->assertStatus(422);
    psSubmit(['currency' => 'ssp'])['response']->assertStatus(422);
    psSubmit(['amount_minor' => 0])['response']->assertStatus(422);
    psSubmit(['amount_minor' => '1200'])['response']->assertStatus(422);
    psSubmit(['category' => 'labour'])['response']->assertStatus(422);
    psSubmit(['contract_version' => 2])['response']->assertStatus(422);
    psSubmit(source: 'pharmacy')['response']->assertStatus(422);

    // The token for another organization does not vouch for this one.
    $this->platform()->organization('globex');
    $this->sendEvent('finance.spending.submitted', 'x', ['request_id' => (string) Str::uuid()], 'acme', token: $this->eventToken('globex', ['source' => 'projects']), source: 'projects')
        ->assertForbidden();

    // Reusing a request id under another key is not a second request.
    $first = psSubmit();
    $first['response']->assertCreated();
    psSubmit(requestId: $first['request_id'], version: 2)['response']->assertStatus(422);

    expect(test()->asTenant('acme', fn () => ProjectSpendingRequest::query()->count()))->toBe(1);
});

it('goes through Finance approval and disbursement, and tells Projects each step', function () {
    $sent = psSubmit();
    psTakeIn($sent['request_id'])->assertSessionHasNoErrors();

    $payment = psPayment($sent['request_id']);
    expect($payment->status)->toBe(PaymentRequest::PENDING_APPROVAL)
        ->and($payment->kind)->toBe(PaymentRequest::EXPENSE)
        ->and($payment->amount_minor)->toBe(120_000);

    // Whoever took it in cannot approve it: Finance's own rule.
    psAs('officer', ['finance.payments.view', 'finance.payments.prepare', 'finance.payments.approve']);
    $this->post($this->workspaceUrl('acme', "payment-requests/{$payment->id}/approve"))->assertSessionHasErrors('request');

    psAs('manager', ['finance.payments.view', 'finance.payments.approve']);
    $this->post($this->workspaceUrl('acme', "payment-requests/{$payment->id}/approve"))->assertSessionHasNoErrors();
    psAs('cashier', ['finance.payments.view', 'finance.payments.disburse']);
    $this->post($this->workspaceUrl('acme', "payment-requests/{$payment->id}/disburse"), ['paid_on' => '2026-11-17'])->assertSessionHasNoErrors();

    $outcomes = psOutcomes();
    expect(array_column($outcomes, 'type'))->toBe(['projects.finance_request.accepted', 'projects.finance_request.approved', 'projects.finance_payment.completed'])
        ->and(array_map(fn ($o) => $o['payload']['sequence'], $outcomes))->toBe([1, 2, 3])
        ->and($outcomes[0]['payload'])->toMatchArray(['request_id' => $sent['request_id'], 'correlation_id' => $sent['correlation_id'], 'finance_reference' => $payment->number, 'organization_id' => $this->platform()->organization('acme')['id']])
        ->and($outcomes[1]['payload'])->toMatchArray(['approved_minor' => 120_000, 'currency' => 'SSP', 'status' => 'approved'])
        ->and($outcomes[2]['payload'])->toMatchArray(['payment_id' => $payment->id, 'amount_minor' => 120_000, 'currency' => 'SSP', 'paid_on' => '2026-11-17', 'status' => 'paid'])
        ->and($outcomes[2]['payload']['journal_reference'])->not->toBeNull()
        ->and(psRow($sent['request_id'])->status)->toBe(ProjectSpending::PAID);

    // Posted once, to the project's materials, tagged with the project.
    $this->asTenant('acme', function () {
        $project = DimensionValue::query()->where('kind', DimensionValue::PROJECT)->sole();
        expect($project->code)->toBe('WASH')
            ->and(JournalLine::query()->where('project_id', $project->id)->where('debit_minor', 120_000)->count())->toBe(1)
            ->and(Journal::query()->count())->toBe(1);
    });
});

it('lets Finance pay less than asked', function () {
    $sent = psSubmit();
    psTakeIn($sent['request_id'], '1500.00')->assertSessionHasErrors('amount');
    psTakeIn($sent['request_id'], '800.00')->assertSessionHasNoErrors();

    psAs('manager', ['finance.payments.view', 'finance.payments.approve']);
    $this->post($this->workspaceUrl('acme', 'payment-requests/'.psPayment($sent['request_id'])->id.'/approve'))->assertSessionHasNoErrors();

    expect(psOutcomes()[1]['payload']['approved_minor'])->toBe(80_000);
});

it('takes in below the approval limit as approved, as any payment request', function () {
    $this->paymentLimit('acme');
    $sent = psSubmit();
    psTakeIn($sent['request_id'])->assertSessionHasNoErrors();

    expect(psPayment($sent['request_id'])->status)->toBe(PaymentRequest::APPROVED)
        ->and(array_column(psOutcomes(), 'type'))->toBe(['projects.finance_request.accepted', 'projects.finance_request.approved']);
});

it('pays only from an account in the request currency', function () {
    $sent = psSubmit(['currency' => 'USD']);
    $sent['response']->assertCreated();

    psTakeIn($sent['request_id'])->assertSessionHasErrors('account_id');
    expect(psRow($sent['request_id'])->status)->toBe(ProjectSpending::RECEIVED)
        ->and(psOutcomes())->toBe([]);
});

it('tells Projects why a request is refused, rejected or withdrawn', function () {
    $refused = psSubmit();
    psAs('manager', ['finance.payments.view', 'finance.payments.approve']);
    $this->post($this->workspaceUrl('acme', 'project-requests/'.psRow($refused['request_id'])->id.'/refuse'), ['reason' => 'No budget code'])->assertSessionHasNoErrors();

    $rejected = psSubmit();
    psTakeIn($rejected['request_id'])->assertSessionHasNoErrors();
    psAs('manager', ['finance.payments.view', 'finance.payments.approve']);
    $this->post($this->workspaceUrl('acme', 'payment-requests/'.psPayment($rejected['request_id'])->id.'/reject'), ['reason' => 'Quote missing'])->assertSessionHasNoErrors();

    $withdrawn = psSubmit();
    psTakeIn($withdrawn['request_id'])->assertSessionHasNoErrors();
    $this->post($this->workspaceUrl('acme', 'payment-requests/'.psPayment($withdrawn['request_id'])->id.'/withdraw'))->assertSessionHasNoErrors();

    $reasons = collect(psOutcomes())->where('type', 'projects.finance_request.rejected')->pluck('payload.reason', 'payload.request_id');
    expect($reasons->get($refused['request_id']))->toBe('No budget code')
        ->and($reasons->get($rejected['request_id']))->toBe('Quote missing')
        ->and($reasons->get($withdrawn['request_id']))->toBe('Withdrawn in Finance.');
});

it('cancels when asked: at once if not taken in, through the payment request if taken in, never once paid', function () {
    // Not taken in: cancelled now.
    $early = psSubmit();
    psCancel($early['request_id'], $early['correlation_id'])->assertCreated();
    expect(psRow($early['request_id'])->status)->toBe(ProjectSpending::CANCELLED);

    // Taken in: flagged; Finance closes the payment request, and Projects hears "cancelled".
    $taken = psSubmit();
    psTakeIn($taken['request_id'])->assertSessionHasNoErrors();
    psCancel($taken['request_id'], $taken['correlation_id'])->assertCreated();
    psTakeIn($taken['request_id'])->assertSessionHasErrors('request');
    expect(psRow($taken['request_id'])->cancellation_requested_at)->not->toBeNull()
        ->and(psPayment($taken['request_id'])->status)->toBe(PaymentRequest::PENDING_APPROVAL);
    psAs('manager', ['finance.payments.view', 'finance.payments.approve']);
    $this->post($this->workspaceUrl('acme', 'payment-requests/'.psPayment($taken['request_id'])->id.'/reject'), ['reason' => 'Cancelled by the project'])->assertSessionHasNoErrors();
    expect(psRow($taken['request_id'])->status)->toBe(ProjectSpending::CANCELLED);

    // Paid before the cancellation arrived: the payment stands; nothing new is said.
    $paid = psSubmit();
    $this->paymentLimit('acme');
    psTakeIn($paid['request_id'])->assertSessionHasNoErrors();
    psAs('cashier', ['finance.payments.view', 'finance.payments.disburse']);
    $this->post($this->workspaceUrl('acme', 'payment-requests/'.psPayment($paid['request_id'])->id.'/disburse'), ['paid_on' => '2026-11-17'])->assertSessionHasNoErrors();
    $before = count(psOutcomes());
    psCancel($paid['request_id'], $paid['correlation_id'])->assertCreated();
    expect(psRow($paid['request_id'])->status)->toBe(ProjectSpending::PAID)
        ->and(count(psOutcomes()))->toBe($before);

    $types = collect(psOutcomes())->groupBy('payload.request_id')->map(fn ($o) => $o->pluck('type')->all());
    expect($types->get($early['request_id']))->toBe(['projects.finance_request.cancelled'])
        ->and($types->get($taken['request_id']))->toBe(['projects.finance_request.accepted', 'projects.finance_request.cancelled']);

    // A cancellation that overtakes its request waits for it.
    psCancel((string) Str::uuid(), (string) Str::uuid())->assertStatus(503);
});

it('takes a corrected revision only after a rejection, never an obsolete one', function () {
    $first = psSubmit();
    psSubmit(version: 2, correlationId: $first['correlation_id'])['response']->assertStatus(422);

    psAs('manager', ['finance.payments.view', 'finance.payments.approve']);
    $this->post($this->workspaceUrl('acme', 'project-requests/'.psRow($first['request_id'])->id.'/refuse'), ['reason' => 'Split it'])->assertSessionHasNoErrors();

    $second = psSubmit(['amount_minor' => 90_000], version: 2, correlationId: $first['correlation_id']);
    $second['response']->assertCreated();
    // A revision 1 arriving late, under a new request id, is not payable.
    psSubmit(version: 1, correlationId: $first['correlation_id'])['response']->assertStatus(422);

    expect(test()->asTenant('acme', fn () => ProjectSpendingRequest::query()->where('correlation_id', $first['correlation_id'])->orderBy('revision')->pluck('status', 'revision')->all()))
        ->toBe([1 => ProjectSpending::REJECTED, 2 => ProjectSpending::RECEIVED]);
});

it('tells Projects when the payment journal is reversed', function () {
    $this->paymentLimit('acme');
    $sent = psSubmit();
    psTakeIn($sent['request_id'])->assertSessionHasNoErrors();
    psAs('cashier', ['finance.payments.view', 'finance.payments.disburse']);
    $this->post($this->workspaceUrl('acme', 'payment-requests/'.psPayment($sent['request_id'])->id.'/disburse'), ['paid_on' => '2026-11-17'])->assertSessionHasNoErrors();

    $this->asTenant('acme', function () use ($sent) {
        $journal = Journal::query()->findOrFail(psPayment($sent['request_id'])->journal_id);
        app(Ledger::class)->reverse($journal, CarbonImmutable::parse('2026-11-18'), 'Paid to the wrong supplier');
    });

    $last = collect(psOutcomes())->last();
    expect($last['type'])->toBe('projects.finance_payment.reversed')
        ->and($last['payload'])->toMatchArray(['amount_minor' => 120_000, 'currency' => 'SSP', 'sequence' => 4, 'status' => 'reversed', 'reason' => 'Paid to the wrong supplier'])
        ->and(psRow($sent['request_id'])->status)->toBe(ProjectSpending::REVERSED);
});
