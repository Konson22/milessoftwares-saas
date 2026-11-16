<?php

use App\Actions\Budgets;
use App\Actions\Spending;
use App\Actions\StaffAdvances;
use App\Enums\BudgetVersionStatus;
use App\Enums\CostCategory;
use App\Enums\ExpenseStatus;
use App\FinanceIntegration\FinanceMode;
use App\FinanceIntegration\FinanceModes;
use App\FinanceIntegration\FinanceRequests;
use App\FinanceIntegration\ModeChangeRefused;
use App\FinanceIntegration\Readiness;
use App\FinanceIntegration\Reconciliation;
use App\FinanceIntegration\RequestStatus;
use App\Models\BudgetLine;
use App\Models\BudgetVersion;
use App\Models\Expense;
use App\Models\FinanceModeChange;
use App\Models\FinancePayment;
use App\Models\FinanceRequest;
use App\Models\FinanceRequestTransition;
use App\Models\Organization;
use App\Models\PettyCashFund;
use App\Models\Project;
use App\Models\User;
use App\Support\ProjectFinance;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;

/*
| Finance integration infrastructure (Phase 3B): approved spending handed to
| Finance only in integrated mode; Finance's outcomes taken through an
| authenticated, tenant-bound, idempotent inbox and applied in order by an
| explicit state machine; modes switched by a person after checks; a
| read-only reconciliation. No organization is integrated outside tests.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 09:00'));
    Notification::fake();
    config(['finance_integration.enabled' => true]);
    $this->platform()->publishKey('finance-test-key', fiKeys()['public']);
});

/**
 * @return array{private: string, public: string}
 */
function fiKeys(bool $other = false): array
{
    static $keys = [];
    $slot = $other ? 'other' : 'main';
    if (! isset($keys[$slot])) {
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => base_path('tests/Concerns/openssl.cnf')];
        $key = openssl_pkey_new($options);
        openssl_pkey_export($key, $private, null, $options);
        $keys[$slot] = ['private' => $private, 'public' => openssl_pkey_get_details($key)['key']];
    }

    return $keys[$slot];
}

/**
 * An event token as the platform would sign it for Finance -> Projects.
 *
 * @param  array{source?: string, audience?: string, org?: string, expires?: string, kid?: string, key?: array{private: string, public: string}}  $o
 */
function fiToken(string $slug = 'acme', array $o = []): string
{
    $keys = $o['key'] ?? fiKeys();
    $config = Configuration::forAsymmetricSigner(new Sha256, InMemory::plainText($keys['private']), InMemory::plainText($keys['public']));
    // The verifier reads the real clock, not the test's travelled one.
    $now = new DateTimeImmutable;

    return $config->builder()
        ->withHeader('kid', $o['kid'] ?? 'finance-test-key')
        ->issuedBy((string) config('platform-client.issuer'))
        ->permittedFor($o['audience'] ?? 'projects')
        ->relatedTo($o['source'] ?? 'finance')
        ->identifiedBy((string) Str::uuid())
        ->issuedAt($now->modify('-10 seconds'))
        ->canOnlyBeUsedAfter($now->modify('-10 seconds'))
        ->expiresAt($now->modify($o['expires'] ?? '+5 minutes'))
        ->withClaim('org', $o['org'] ?? test()->organization($slug)->id)
        ->withClaim('scope', 'events.write')
        ->getToken($config->signer(), $config->signingKey())
        ->toString();
}

/**
 * Finance reporting an outcome the way its outbox would.
 *
 * @param  array<string, mixed>  $payload
 */
function fiSend(string $type, string $transaction, array $payload, string $slug = 'acme', ?string $token = null, string $source = 'finance', ?string $organizationId = null): TestResponse
{
    return test()->withHeader('Authorization', 'Bearer '.($token ?? fiToken($slug, ['source' => $source])))
        ->postJson('http://finance.internal/projects/events', [
            'event_id' => (string) Str::uuid(),
            'event_type' => $type,
            'schema_version' => 1,
            'organization_id' => $organizationId ?? test()->organization($slug)->id,
            'source_app' => $source,
            'source_transaction_id' => $transaction,
            'occurred_at' => now()->toIso8601String(),
            'payload' => $payload,
        ]);
}

/**
 * An outcome for the request, with its references and the next sequence.
 *
 * @param  array<string, mixed>  $extra
 */
function fiOutcome(FinanceRequest $request, string $what, int $sequence, array $extra = [], ?string $transaction = null, string $slug = 'acme'): TestResponse
{
    return fiSend("projects.{$what}", $transaction ?? "fin:{$request->id}:{$sequence}", [
        'request_id' => $request->id,
        'correlation_id' => $request->correlation_id,
        'sequence' => $sequence,
        ...$extra,
    ], $slug);
}

function fiMode(string $slug, FinanceMode $mode): void
{
    test()->organization($slug)->forceFill(['finance_mode' => $mode->value])->save();
}

/**
 * A project with an approved 10,000.00 materials budget, and 1,200.00 of
 * spending requested by one member and approved by another.
 *
 * @return array{0: Project, 1: Expense, 2: User}
 */
function fiApproved(string $slug = 'acme', string $code = 'WASH'): array
{
    $project = collabProject($slug, $code);

    return test()->asTenant($slug, function () use ($project) {
        $requester = User::factory()->create();
        $approver = User::factory()->create();
        $version = tap(tap(new BudgetVersion)->forceFill(['project_id' => $project->id, 'number' => 1, 'status' => BudgetVersionStatus::Approved, 'total_minor' => 1_000_000, 'created_by' => $requester->id]))->save();
        $version->lines()->create(['project_id' => $project->id, 'category' => CostCategory::Materials, 'amount_minor' => 1_000_000]);
        $expense = Spending::request($project, ['category' => CostCategory::Materials, 'description' => 'Pumps', 'supplier' => 'Pumps Ltd', 'amount_minor' => 120_000, 'grant_id' => null], $requester);
        Spending::approve($expense, null, $approver);

        return [$project, $expense->refresh(), $approver];
    });
}

function fiRequest(string $slug = 'acme', ?Expense $expense = null): FinanceRequest
{
    return test()->asTenant($slug, fn () => FinanceRequest::query()->when($expense, fn ($q) => $q->where('expense_id', $expense?->id))->latest('submitted_at')->firstOrFail());
}

function fiRejections(?string $reason = null): int
{
    return DB::table('finance_inbox_rejections')->when($reason, fn ($q) => $q->where('reason', $reason))->count();
}

it('hands approved spending to Finance only when the organization is integrated', function () {
    [, $legacy] = fiApproved('acme');
    expect(test()->asTenant('acme', fn () => FinanceRequest::query()->count()))->toBe(0)
        ->and($this->financeEvents('acme', 'finance.spending.submitted'))->toBe([])
        ->and($legacy->status)->toBe(ExpenseStatus::Approved);

    fiMode('globex', FinanceMode::Integrated);
    [$project, $expense] = fiApproved('globex', 'FOOD');
    $request = fiRequest('globex');
    $events = $this->financeEvents('globex', 'finance.spending.submitted');

    expect($request->status)->toBe(RequestStatus::Submitted)
        ->and($request->expense_id)->toBe($expense->id)
        ->and($request->amount_minor)->toBe(120_000)
        ->and($request->currency)->toBe('USD')
        ->and($request->idempotency_key)->toBe("finance-request:{$request->id}:r1")
        ->and($events)->toHaveCount(1)
        ->and($events[0]['transaction'])->toBe("finance-request:{$request->id}:r1:submitted")
        ->and($events[0]['payload'])->toMatchArray([
            'originating_app' => 'projects', 'organization_id' => $this->organization('globex')->id, 'project_id' => $project->id,
            'request_id' => $request->id, 'correlation_id' => $request->correlation_id, 'idempotency_key' => $request->idempotency_key,
            'version' => 1, 'contract_version' => 1, 'currency' => 'USD', 'amount_minor' => 120_000, 'status' => 'submitted',
        ])
        ->and($events[0]['payload'])->not->toHaveKey('hourly_rate')
        ->and(test()->asTenant('globex', fn () => FinanceRequestTransition::query()->pluck('to_status')->all()))->toBe(['submitted']);

    // Switched off for the deployment: nothing is sent, even if integrated.
    config(['finance_integration.enabled' => false]);
    fiApproved('globex', 'SEED');
    expect(test()->asTenant('globex', fn () => FinanceRequest::query()->count()))->toBe(1);
});

it('applies Finance outcomes in order and counts what Finance paid as paid', function () {
    fiMode('acme', FinanceMode::Integrated);
    [$project, $expense] = fiApproved();
    $request = fiRequest();

    fiOutcome($request, 'finance_request.accepted', 1, ['finance_reference' => 'FIN-PR-0042'])->assertCreated();
    fiOutcome($request, 'finance_request.approved', 2, ['approved_minor' => 120_000, 'currency' => 'USD'])->assertCreated();
    fiOutcome($request, 'finance_payment.completed', 3, ['payment_id' => 'PAY-1', 'amount_minor' => 50_000, 'currency' => 'USD', 'paid_on' => '2026-11-17', 'journal_reference' => 'JV-9'])->assertCreated();

    $request->refresh();
    $figures = test()->asTenant('acme', fn () => ProjectFinance::for($project));
    expect($request->status)->toBe(RequestStatus::PartiallyPaid)
        ->and($request->paid_minor)->toBe(50_000)
        ->and($figures['committedMinor'])->toBe(70_000)
        ->and($figures['paidMinor'])->toBe(50_000)
        ->and($figures['provenance']['source'])->toBe('projects_and_finance');

    fiOutcome($request, 'finance_payment.completed', 4, ['payment_id' => 'PAY-2', 'amount_minor' => 70_000, 'currency' => 'USD', 'paid_on' => '2026-11-18'])->assertCreated();
    $request->refresh();
    $figures = test()->asTenant('acme', fn () => ProjectFinance::for($project));

    expect($request->status)->toBe(RequestStatus::Paid)
        ->and($request->finance_reference)->toBe('FIN-PR-0042')
        ->and($figures['committedMinor'])->toBe(0)
        ->and($figures['paidMinor'])->toBe(120_000)
        // The expense itself is not rewritten, and Projects sends no payment event of its own.
        ->and($expense->refresh()->status)->toBe(ExpenseStatus::Approved)
        ->and($this->financeEvents('acme', 'finance.project_expense.paid'))->toBe([])
        ->and(test()->asTenant('acme', fn () => FinanceRequestTransition::query()->orderByRaw('coalesce(finance_sequence, 0)')->get()->map(fn ($t) => "{$t->from_status}>{$t->to_status}")->all()))
        ->toBe(['>submitted', 'submitted>accepted', 'accepted>approved', 'approved>partially_paid', 'partially_paid>paid']);
});

it('answers a retry as a duplicate and refuses a reused transaction id with other content', function () {
    fiMode('acme', FinanceMode::Integrated);
    fiApproved();
    $request = fiRequest();

    fiOutcome($request, 'finance_request.accepted', 1, ['finance_reference' => 'FIN-1'], 'fin:accept')->assertCreated();
    fiOutcome($request, 'finance_request.accepted', 1, ['finance_reference' => 'FIN-1'], 'fin:accept')->assertOk()->assertJson(['status' => 'duplicate']);
    fiOutcome($request, 'finance_request.accepted', 1, ['finance_reference' => 'FIN-2'], 'fin:accept')->assertStatus(409);

    fiOutcome($request, 'finance_request.approved', 2, ['approved_minor' => 120_000])->assertCreated();
    fiOutcome($request, 'finance_payment.completed', 3, ['payment_id' => 'PAY-1', 'amount_minor' => 10_000, 'currency' => 'USD', 'paid_on' => '2026-11-17'], 'fin:pay-1')->assertCreated();
    fiOutcome($request, 'finance_payment.completed', 3, ['payment_id' => 'PAY-1', 'amount_minor' => 10_000, 'currency' => 'USD', 'paid_on' => '2026-11-17'], 'fin:pay-1')->assertOk();
    // The same payment again under a new transaction id is not paid twice.
    fiOutcome($request, 'finance_payment.completed', 4, ['payment_id' => 'PAY-1', 'amount_minor' => 10_000, 'currency' => 'USD', 'paid_on' => '2026-11-17'], 'fin:pay-1-again')->assertStatus(409)->assertJson(['error' => 'duplicate_payment']);

    expect($request->refresh()->paid_minor)->toBe(10_000)
        ->and($request->finance_reference)->toBe('FIN-1')
        ->and(test()->asTenant('acme', fn () => FinancePayment::query()->count()))->toBe(1)
        ->and(fiRejections('conflict'))->toBe(1)
        ->and(fiRejections('duplicate_payment'))->toBe(1);
});

it('waits for a missing earlier outcome and refuses an older one', function () {
    fiMode('acme', FinanceMode::Integrated);
    fiApproved();
    $request = fiRequest();

    // Approved arrives before accepted: not kept, so Finance's retry succeeds later.
    fiOutcome($request, 'finance_request.approved', 2, ['approved_minor' => 120_000], 'fin:approve')->assertStatus(503)->assertHeader('Retry-After');
    expect(DB::table('shared_module_inbox')->count())->toBe(0);

    fiOutcome($request, 'finance_request.accepted', 1, ['finance_reference' => 'FIN-1'], 'fin:accept')->assertCreated();
    fiOutcome($request, 'finance_request.approved', 2, ['approved_minor' => 120_000], 'fin:approve')->assertCreated();
    // A late, different outcome with an old number.
    fiOutcome($request, 'finance_request.rejected', 1, ['reason' => 'Late'], 'fin:reject-late')->assertStatus(409)->assertJson(['error' => 'stale_sequence']);

    expect($request->refresh()->status)->toBe(RequestStatus::Approved)
        ->and($request->finance_sequence)->toBe(2)
        ->and(fiRejections('stale_sequence'))->toBe(1);
});

it('refuses invalid transitions, mismatched amounts and currencies, and unknown references', function () {
    fiMode('acme', FinanceMode::Integrated);
    fiApproved();
    $request = fiRequest();

    fiOutcome($request, 'finance_payment.completed', 1, ['payment_id' => 'PAY-1', 'amount_minor' => 1_000, 'currency' => 'USD', 'paid_on' => '2026-11-17'])->assertStatus(409)->assertJson(['error' => 'invalid_transition']);
    fiOutcome($request, 'finance_request.accepted', 1, ['finance_reference' => 'FIN-1', 'currency' => 'EUR'])->assertStatus(422)->assertJson(['error' => 'currency_mismatch']);
    fiOutcome($request, 'finance_request.accepted', 1, ['finance_reference' => 'FIN-1'])->assertCreated();
    fiOutcome($request, 'finance_request.approved', 2, ['approved_minor' => 999_999])->assertStatus(422)->assertJson(['error' => 'amount_mismatch']);
    fiOutcome($request, 'finance_request.approved', 2, ['approved_minor' => 100_000])->assertCreated();
    // Finance approved less: payments stop at what it approved.
    fiOutcome($request, 'finance_payment.completed', 3, ['payment_id' => 'PAY-1', 'amount_minor' => 100_001, 'currency' => 'USD', 'paid_on' => '2026-11-17'])->assertStatus(422)->assertJson(['error' => 'amount_mismatch']);
    fiOutcome($request, 'finance_payment.completed', 3, ['payment_id' => 'PAY-1', 'amount_minor' => 5_000, 'paid_on' => '2026-11-17'])->assertStatus(422)->assertJson(['error' => 'invalid_payload']);

    fiSend('projects.finance_request.accepted', 'fin:unknown', ['request_id' => (string) Str::uuid(), 'correlation_id' => (string) Str::uuid(), 'sequence' => 1, 'finance_reference' => 'X'])
        ->assertStatus(422)->assertJson(['error' => 'unknown_request']);
    fiSend('projects.finance_request.rejected', 'fin:wrong-correlation', ['request_id' => $request->id, 'correlation_id' => (string) Str::uuid(), 'sequence' => 3])
        ->assertStatus(422)->assertJson(['error' => 'correlation_mismatch']);
    fiOutcome($request, 'finance_payment.reversed', 3, ['payment_id' => 'NOPE', 'amount_minor' => 1, 'currency' => 'USD'])->assertStatus(422)->assertJson(['error' => 'unknown_payment']);
    fiSend('projects.finance_request.exploded', 'fin:odd', ['request_id' => $request->id])->assertStatus(422)->assertJson(['error' => 'unsupported_event']);
    fiSend('finance.project_expense.paid', 'fin:wrong-module', ['request_id' => $request->id])->assertStatus(422)->assertJson(['error' => 'wrong_module']);
    $this->withHeader('Authorization', 'Bearer '.fiToken())->postJson('http://finance.internal/projects/events', ['event_type' => 'nonsense'])
        ->assertStatus(422)->assertJson(['error' => 'invalid_envelope']);

    expect($request->refresh()->status)->toBe(RequestStatus::Approved)
        ->and($request->approved_minor)->toBe(100_000)
        ->and($request->paid_minor)->toBe(0)
        ->and(fiRejections())->toBe(11);
});

it('records failed payments and reversals without rewriting anything else', function () {
    fiMode('acme', FinanceMode::Integrated);
    [$project, $expense] = fiApproved();
    $request = fiRequest();

    fiOutcome($request, 'finance_request.accepted', 1, ['finance_reference' => 'FIN-1'])->assertCreated();
    fiOutcome($request, 'finance_request.approved', 2, ['approved_minor' => 120_000])->assertCreated();
    fiOutcome($request, 'finance_payment.failed', 3, ['reason' => 'Bank refused the transfer'])->assertCreated();
    expect($request->refresh()->status)->toBe(RequestStatus::Failed);

    fiOutcome($request, 'finance_payment.completed', 4, ['payment_id' => 'PAY-1', 'amount_minor' => 120_000, 'currency' => 'USD', 'paid_on' => '2026-11-18', 'journal_reference' => 'JV-1'])->assertCreated();
    fiOutcome($request, 'finance_transaction.corrected', 5, ['payment_id' => 'PAY-1', 'journal_reference' => 'JV-1-corrected', 'note' => 'Wrong account'])->assertCreated();
    fiOutcome($request, 'finance_transaction.corrected', 6, ['payment_id' => 'PAY-1', 'journal_reference' => 'JV-x', 'amount_minor' => 1])->assertStatus(422);
    fiOutcome($request, 'finance_payment.reversed', 6, ['payment_id' => 'PAY-1', 'amount_minor' => 20_000, 'currency' => 'USD', 'reason' => 'Overpaid'])->assertCreated();
    expect($request->refresh()->status)->toBe(RequestStatus::PartiallyPaid)
        ->and(test()->asTenant('acme', fn () => ProjectFinance::for($project)['paidMinor']))->toBe(100_000);

    fiOutcome($request, 'finance_payment.reversed', 7, ['payment_id' => 'PAY-1', 'amount_minor' => 100_001, 'currency' => 'USD'])->assertStatus(422)->assertJson(['error' => 'amount_mismatch']);
    fiOutcome($request, 'finance_payment.reversed', 7, ['payment_id' => 'PAY-1', 'amount_minor' => 100_000, 'currency' => 'USD'])->assertCreated();

    $payment = test()->asTenant('acme', fn () => FinancePayment::query()->sole());
    expect($request->refresh()->status)->toBe(RequestStatus::Reversed)
        ->and($request->reversed_minor)->toBe(120_000)
        ->and($payment->status)->toBe(FinancePayment::REVERSED)
        ->and($payment->journal_reference)->toBe('JV-1-corrected')
        ->and($expense->refresh()->status)->toBe(ExpenseStatus::Approved)
        // Reversed in full: nothing paid, and nothing authorized any more (it is sent again as a revision).
        ->and(test()->asTenant('acme', fn () => ProjectFinance::for($project)))->toMatchArray(['paidMinor' => 0, 'committedMinor' => 0]);

    // History is kept as it happened.
    $first = test()->asTenant('acme', fn () => FinanceRequestTransition::query()->firstOrFail());
    expect(fn () => $first->forceFill(['to_status' => 'paid'])->save())->toThrow(LogicException::class)
        ->and(fn () => $first->delete())->toThrow(LogicException::class);
});

it('takes outcomes only from Finance, with a valid token for this organization', function () {
    fiMode('acme', FinanceMode::Integrated);
    fiApproved();
    $request = fiRequest();
    $payload = ['request_id' => $request->id, 'correlation_id' => $request->correlation_id, 'sequence' => 1, 'finance_reference' => 'FIN-1'];

    $this->postJson('http://finance.internal/projects/events', [])->assertUnauthorized();
    fiSend('projects.finance_request.accepted', 'a', $payload, token: fiToken('acme', ['expires' => '-1 minute']))->assertUnauthorized();
    fiSend('projects.finance_request.accepted', 'b', $payload, token: fiToken('acme', ['audience' => 'finance']))->assertUnauthorized();
    // Signed by a key the platform does not publish (revoked or forged).
    fiSend('projects.finance_request.accepted', 'c', $payload, token: fiToken('acme', ['key' => fiKeys(other: true)]))->assertUnauthorized();
    fiSend('projects.finance_request.accepted', 'd', $payload, token: fiToken('acme', ['kid' => 'retired-key']))->assertUnauthorized();
    // Another product, even one the platform vouches for, is not Finance.
    fiSend('projects.finance_request.accepted', 'e', $payload, source: 'pharmacy')->assertForbidden()->assertJson(['error' => 'source_not_allowed']);
    fiSend('projects.finance_request.accepted', 'f', $payload, token: fiToken('acme', ['source' => 'pharmacy']))->assertForbidden()->assertJson(['error' => 'source_mismatch']);
    fiSend('projects.finance_request.accepted', 'g', $payload, token: fiToken('globex'))->assertForbidden()->assertJson(['error' => 'organization_mismatch']);

    expect($request->refresh()->status)->toBe(RequestStatus::Submitted)
        ->and(DB::table('shared_module_inbox')->count())->toBe(0)
        ->and(fiRejections())->toBe(8)
        ->and(fiRejections('invalid_token'))->toBe(5);
});

it('keeps organizations apart and refuses organizations not integrated', function () {
    fiMode('acme', FinanceMode::Integrated);
    fiApproved('acme');
    $acmeRequest = fiRequest('acme');

    // Globex is integrated too, and Finance vouches for it: acme's request is still not its own.
    fiMode('globex', FinanceMode::Integrated);
    fiSend('projects.finance_request.accepted', 'x', ['request_id' => $acmeRequest->id, 'correlation_id' => $acmeRequest->correlation_id, 'sequence' => 1, 'finance_reference' => 'FIN-G'], 'globex')
        ->assertStatus(422)->assertJson(['error' => 'unknown_request']);

    // An organization still in legacy mode takes no outcomes.
    fiMode('initech', FinanceMode::Legacy);
    fiSend('projects.finance_request.accepted', 'y', ['request_id' => $acmeRequest->id, 'correlation_id' => $acmeRequest->correlation_id, 'sequence' => 1], 'initech')
        ->assertStatus(409)->assertJson(['error' => 'not_integrated']);

    expect($acmeRequest->refresh()->status)->toBe(RequestStatus::Submitted)
        ->and($acmeRequest->finance_reference)->toBeNull()
        ->and(DB::table('finance_inbox_rejections')->where('organization_id', $this->organization('globex')->id)->count())->toBe(1);
});

it('lets nobody in Projects mark spending Finance pays as paid', function () {
    fiMode('acme', FinanceMode::Integrated);
    [$project, $expense] = fiApproved();

    // The organization's owner, holding every permission.
    $this->actingAsMember('acme', owner: true);
    $this->post($this->organizationUrl('acme', "projects/{$project->id}/expenses/{$expense->id}/pay"), ['paid_on' => '2026-11-17', 'reference' => 'CHQ-1'])
        ->assertSessionHasErrors('expense');
    expect(fn () => test()->asTenant('acme', fn () => Spending::record($project, ['category' => CostCategory::Materials, 'description' => 'Cash buy', 'supplier' => null, 'amount_minor' => 1_000, 'grant_id' => null], CarbonImmutable::today(), User::factory()->create())))
        ->toThrow(ValidationException::class);

    expect($expense->refresh()->status)->toBe(ExpenseStatus::Approved)
        ->and($this->financeEvents('acme', 'finance.project_expense.paid'))->toBe([]);
    // Nor is it offered: the approvals page lists nothing to pay.
    $this->get($this->organizationUrl('acme', 'approvals'))->assertOk()->assertInertia(fn ($page) => $page->where('payments', null));

    // Suspended: still Finance's to pay.
    fiMode('acme', FinanceMode::Suspended);
    $this->post($this->organizationUrl('acme', "projects/{$project->id}/expenses/{$expense->id}/pay"), ['paid_on' => '2026-11-17', 'reference' => 'CHQ-1'])
        ->assertSessionHasErrors('expense');
});

it('asks Finance to cancel, and refuses once Finance has paid any of it', function () {
    fiMode('acme', FinanceMode::Integrated);
    [, $expense, $approver] = fiApproved();
    $request = fiRequest();

    test()->asTenant('acme', fn () => Spending::cancel($expense, 'No longer needed', $approver));
    $events = $this->financeEvents('acme', 'finance.spending.cancelled');
    expect($request->refresh()->status)->toBe(RequestStatus::CancellationRequested)
        ->and($expense->refresh()->status)->toBe(ExpenseStatus::Cancelled)
        ->and($events)->toHaveCount(1)
        ->and($events[0]['payload'])->toMatchArray(['request_id' => $request->id, 'correlation_id' => $request->correlation_id, 'status' => 'cancellation_requested']);

    fiOutcome($request, 'finance_request.cancelled', 1)->assertCreated();
    expect($request->refresh()->status)->toBe(RequestStatus::Cancelled);

    [, $second, $by] = fiApproved('acme', 'SEED');
    $paidRequest = fiRequest('acme', $second);
    fiOutcome($paidRequest, 'finance_request.accepted', 1, ['finance_reference' => 'FIN-2'])->assertCreated();
    fiOutcome($paidRequest, 'finance_request.approved', 2, ['approved_minor' => 120_000])->assertCreated();
    fiOutcome($paidRequest, 'finance_payment.completed', 3, ['payment_id' => 'PAY-9', 'amount_minor' => 1_000, 'currency' => 'USD', 'paid_on' => '2026-11-17'])->assertCreated();

    expect(fn () => test()->asTenant('acme', fn () => Spending::cancel($second, 'Changed our mind', $by)))->toThrow(ValidationException::class)
        ->and($second->refresh()->status)->toBe(ExpenseStatus::Approved);
});

it('switches modes only along the allowed path, with a reason, after the checks', function () {
    [, $legacyCommitment, $approver] = fiApproved();
    $acme = fn () => Organization::query()->findOrFail($this->organization('acme')->id);
    $modes = app(FinanceModes::class);

    expect(fn () => $modes->change($acme(), FinanceMode::Integrated, 'Go live', 'ops'))->toThrow(ModeChangeRefused::class, 'legacy cannot switch to integrated')
        ->and(fn () => $modes->change($acme(), FinanceMode::ReconciliationRequired, '  ', 'ops'))->toThrow(ModeChangeRefused::class, 'Say why');

    $modes->change($acme(), FinanceMode::ReconciliationRequired, 'Preparing for Finance', 'ops');

    // An approved commitment paid in Projects' books, and a petty cash box, block readiness.
    test()->asTenant('acme', fn () => PettyCashFund::query()->forceCreate(['name' => 'Office box', 'currency' => 'USD', 'custodian_id' => $approver->id, 'float_minor' => 10_000, 'status' => PettyCashFund::ACTIVE, 'created_by' => $approver->id]));
    try {
        $modes->change($acme(), FinanceMode::Ready, 'Checks done', 'ops');
        $this->fail('Readiness should block.');
    } catch (ModeChangeRefused $e) {
        $blocking = collect($e->checks)->filter(fn ($c) => $c['blocking'] && ! $c['passed'])->pluck('key')->all();
        expect($blocking)->toContain('legacy_commitments', 'petty_cash');
    }

    test()->asTenant('acme', function () use ($legacyCommitment, $approver) {
        Spending::cancel($legacyCommitment, 'Released before handover', $approver);
        PettyCashFund::query()->update(['status' => PettyCashFund::MOVED]);
    });
    $modes->change($acme(), FinanceMode::Ready, 'Checks done', 'ops');

    config(['finance_integration.enabled' => false]);
    expect(fn () => $modes->change($acme(), FinanceMode::Integrated, 'Go live', 'ops'))->toThrow(ModeChangeRefused::class, 'switched on');
    config(['finance_integration.enabled' => true]);
    $modes->change($acme(), FinanceMode::Integrated, 'Go live', 'ops');

    expect(fn () => $modes->change($acme(), FinanceMode::Legacy, 'Back', 'ops'))->toThrow(ModeChangeRefused::class);
    fiApproved('acme', 'SEED');
    $modes->change($acme(), FinanceMode::Suspended, 'Finance outage', 'ops');
    // An open request in Finance: Projects does not take payment back over.
    expect(fn () => $modes->change($acme(), FinanceMode::Legacy, 'Back to Projects', 'ops'))->toThrow(ModeChangeRefused::class, 'Requests still open in Finance');

    expect(FinanceMode::of($acme()))->toBe(FinanceMode::Suspended)
        ->and(test()->asTenant('acme', fn () => FinanceModeChange::query()->orderBy('created_at')->get()->map(fn ($c) => "{$c->from_mode}>{$c->to_mode}")->all()))
        ->toBe(['legacy>reconciliation_required', 'reconciliation_required>ready', 'ready>integrated', 'integrated>suspended']);
});

it('reports the readiness checks without changing anything', function () {
    fiApproved();
    $this->platform()->notEntitledTo[] = 'finance';

    $checks = collect(app(Readiness::class)->check($this->organization('acme'), FinanceMode::Ready))->keyBy('key');

    expect($checks['finance_connected']['passed'])->toBeFalse()
        ->and($checks['legacy_commitments']['count'])->toBe(1)
        ->and($checks['pending_approvals']['blocking'])->toBeFalse()
        ->and(FinanceMode::of($this->organization('acme')->refresh()))->toBe(FinanceMode::Legacy);

    $this->artisan('projects:finance-mode', ['organization' => 'acme'])->expectsOutputToContain('Can switch to: reconciliation_required')->assertSuccessful();
    $this->artisan('projects:finance-mode', ['organization' => 'acme', 'mode' => 'integrated', '--reason' => 'x'])->assertFailed();
    expect(FinanceMode::of($this->organization('acme')->refresh()))->toBe(FinanceMode::Legacy);
});

it('reconciles requests with Finance, read-only', function () {
    fiMode('acme', FinanceMode::Integrated);
    fiApproved();
    $request = fiRequest();
    fiOutcome($request, 'finance_request.accepted', 1, ['finance_reference' => 'FIN-1'])->assertCreated();
    fiOutcome($request, 'finance_request.rejected', 2, ['reason' => 'No budget code'])->assertCreated();

    [, $seed] = fiApproved('acme', 'SEED');
    fiOutcome(fiRequest('acme', $seed), 'finance_request.approved', 5, ['approved_minor' => 1])->assertStatus(503);
    fiOutcome(fiRequest('acme', $seed), 'finance_request.accepted', 1, ['finance_reference' => 'FIN-1'])->assertStatus(409);
    $this->travel(2)->days();

    $before = [DB::table('finance_requests')->count(), DB::table('finance_request_transitions')->count(), DB::table('finance_inbox_rejections')->count(), DB::table('platform_outbox')->count()];
    $result = app(Reconciliation::class)->run($this->organization('acme')->id);

    expect($result['counts'])->toMatchArray(['rejected_open' => 1, 'unacknowledged' => 1, 'duplicate' => 1])
        ->and(collect($result['findings'])->pluck('detail')->implode(' '))->not->toContain('120000')
        ->and([DB::table('finance_requests')->count(), DB::table('finance_request_transitions')->count(), DB::table('finance_inbox_rejections')->count(), DB::table('platform_outbox')->count()])->toBe($before);

    $this->artisan('projects:finance-reconcile', ['--organization' => 'acme'])->expectsOutputToContain('rejected_open')->assertFailed();
});

/*
| Phase 3C: resubmission under one correlation id, the amounts of a request
| kept apart, cancellation crossing Finance's decisions, and staff advances
| kept out of integrated mode.
*/

it('keeps the amounts of a request apart, and never counts unpaid requested money as authorized', function () {
    fiMode('acme', FinanceMode::Integrated);
    [$project] = fiApproved();
    $request = fiRequest();
    $figures = fn () => test()->asTenant('acme', fn () => ProjectFinance::for($project));
    $amounts = fn () => [$request->refresh()->awaitingFinanceMinor(), $request->approved_minor, $request->outstandingAuthorizedMinor(), $request->netPaidMinor(), $request->openCommitmentMinor()];

    // Sent and taken in: all of it waits for Finance, none is authorized yet.
    expect($amounts())->toBe([120_000, null, 0, 0, 120_000])->and($figures()['committedMinor'])->toBe(120_000);
    fiOutcome($request, 'finance_request.accepted', 1, ['finance_reference' => 'PR-1'])->assertCreated();
    expect($amounts())->toBe([120_000, null, 0, 0, 120_000]);

    // Finance approves less than asked: only that is authorized; approval is not payment.
    fiOutcome($request, 'finance_request.approved', 2, ['approved_minor' => 80_000, 'currency' => 'USD'])->assertCreated();
    expect($amounts())->toBe([0, 80_000, 80_000, 0, 80_000])
        ->and($figures()['committedMinor'])->toBe(80_000)
        ->and($figures()['paidMinor'])->toBe(0);

    // Paid in two parts; the 40,000 Finance did not approve is not committed.
    fiOutcome($request, 'finance_payment.completed', 3, ['payment_id' => 'P-1', 'amount_minor' => 30_000, 'currency' => 'USD', 'paid_on' => '2026-11-17'])->assertCreated();
    expect($amounts())->toBe([0, 80_000, 50_000, 30_000, 50_000]);
    fiOutcome($request, 'finance_payment.completed', 4, ['payment_id' => 'P-2', 'amount_minor' => 50_000, 'currency' => 'USD', 'paid_on' => '2026-11-18'])->assertCreated();
    expect($request->refresh()->status)->toBe(RequestStatus::Paid)
        ->and($amounts())->toBe([0, 80_000, 0, 80_000, 0])
        ->and($figures()['committedMinor'])->toBe(0)
        ->and($figures()['paidMinor'])->toBe(80_000);

    // Part of a payment taken back: paid goes down, the approval still stands for the rest.
    fiOutcome($request, 'finance_payment.reversed', 5, ['payment_id' => 'P-2', 'amount_minor' => 50_000, 'currency' => 'USD', 'reason' => 'Bounced'])->assertCreated();
    expect($request->refresh()->status)->toBe(RequestStatus::PartiallyPaid)
        ->and($request->reversed_minor)->toBe(50_000)
        ->and($amounts())->toBe([0, 80_000, 50_000, 30_000, 50_000])
        ->and($figures()['paidMinor'])->toBe(30_000);

    // Refused: nothing of it stays committed.
    [, $other] = fiApproved('acme', 'SEED');
    $refused = fiRequest('acme', $other);
    fiOutcome($refused, 'finance_request.rejected', 1, ['reason' => 'No supporting quote'])->assertCreated();
    expect($refused->refresh()->openCommitmentMinor())->toBe(0);
});

it('sends refused spending again as a new revision under the same correlation id', function () {
    fiMode('acme', FinanceMode::Integrated);
    [$project, $expense, $approver] = fiApproved();
    $first = fiRequest();
    $permissions = ['projects.view', 'projects.finance.view', 'finance.expenses.approve'];
    $resubmit = function (string $amount, User $by) use ($permissions, $project, $expense) {
        $this->actingAsMember('acme', $permissions, user: $by);

        return $this->post($this->workspaceUrl('acme', "/projects/{$project->id}/expenses/{$expense->id}/resubmit"), ['amount' => $amount, 'note' => 'Quote attached']);
    };

    // Still with Finance: not sent again.
    $resubmit('1200.00', $approver)->assertSessionHasErrors('expense');

    fiOutcome($first, 'finance_request.rejected', 1, ['reason' => 'No supporting quote'])->assertCreated();
    // The page explains why, and offers to send it again.
    $this->actingAsMember('acme', $permissions, user: $approver);
    $this->get($this->workspaceUrl('acme', "/projects/{$project->id}/expenses"))
        ->assertInertia(fn ($page) => $page->where('expenses.0.financeRequest.status', 'rejected')
            ->where('expenses.0.financeRequest.reason', 'No supporting quote')
            ->where('expenses.0.can.resubmit', true));

    $resubmit('1300.00', $approver)->assertSessionHasErrors('amount');
    $resubmit('1100.00', $approver)->assertSessionHasNoErrors();

    $requests = test()->asTenant('acme', fn () => FinanceRequest::query()->orderBy('revision')->get());
    $events = $this->financeEvents('acme', 'finance.spending.submitted');
    $reason = test()->asTenant('acme', fn () => FinanceRequestTransition::query()->where('finance_request_id', $first->id)->where('to_status', 'rejected')->firstOrFail()->facts['reason'] ?? null);
    expect($requests)->toHaveCount(2)
        ->and($requests[1]->revision)->toBe(2)
        ->and($requests[1]->correlation_id)->toBe($first->correlation_id)
        ->and($requests[1]->amount_minor)->toBe(110_000)
        ->and($requests[1]->idempotency_key)->toBe("finance-request:{$requests[1]->id}:r2")
        // The refused revision and its reason stay as they were.
        ->and($requests[0]->status)->toBe(RequestStatus::Rejected)
        ->and($reason)->toBe('No supporting quote')
        ->and($events)->toHaveCount(2)
        ->and($events[1]['payload'])->toMatchArray(['request_id' => $requests[1]->id, 'correlation_id' => $first->correlation_id, 'version' => 2, 'amount_minor' => 110_000, 'supersedes_request_id' => $first->id, 'revision_note' => 'Quote attached']);

    // One revision open at a time.
    $resubmit('1100.00', $approver)->assertSessionHasErrors('expense');
    // The requester does not send their own spending again.
    fiOutcome($requests[1], 'finance_request.rejected', 1, ['reason' => 'Still no quote'])->assertCreated();
    $requester = test()->asTenant('acme', fn () => User::query()->findOrFail($expense->recorded_by));
    $resubmit('1100.00', $requester)->assertForbidden();
    expect(test()->asTenant('acme', fn () => FinanceRequest::query()->count()))->toBe(2);
});

it('keeps a cancellation asked for when Finance takes the request in or approves it meanwhile', function () {
    fiMode('acme', FinanceMode::Integrated);
    [, $expense, $approver] = fiApproved();
    $request = fiRequest();

    test()->asTenant('acme', fn () => Spending::cancel($expense, 'Not needed', $approver));
    fiOutcome($request, 'finance_request.accepted', 1, ['finance_reference' => 'PR-7'])->assertCreated();
    fiOutcome($request, 'finance_request.approved', 2, ['approved_minor' => 120_000, 'currency' => 'USD'])->assertCreated();

    expect($request->refresh()->status)->toBe(RequestStatus::CancellationRequested)
        ->and($request->finance_reference)->toBe('PR-7')
        ->and($request->approved_minor)->toBe(120_000)
        ->and($request->openCommitmentMinor())->toBe(0);

    fiOutcome($request, 'finance_request.cancelled', 3)->assertCreated();
    expect($request->refresh()->status)->toBe(RequestStatus::Cancelled);
});

it('refuses an outcome naming another organization in its payload', function () {
    fiMode('acme', FinanceMode::Integrated);
    fiApproved();
    $request = fiRequest();

    fiOutcome($request, 'finance_request.accepted', 1, ['finance_reference' => 'PR-1', 'organization_id' => $this->organization('globex')->id])->assertStatus(422);
    expect($request->refresh()->status)->toBe(RequestStatus::Submitted)->and(fiRejections('organization_mismatch'))->toBe(1);
});

it('keeps staff advances out of integrated mode', function () {
    $acme = fn () => Organization::query()->findOrFail($this->organization('acme')->id);
    $acme()->forceFill(['advance_max_minor' => 50_000, 'advance_retire_days' => 14])->save();

    $checks = collect(app(Readiness::class)->check($acme(), FinanceMode::Ready))->keyBy('key');
    expect($checks['advances_not_integrated'])->toMatchArray(['passed' => false, 'blocking' => true]);

    fiMode('acme', FinanceMode::Integrated);
    $holder = test()->asTenant('acme', fn () => User::factory()->create());
    expect(fn () => test()->asTenant('acme', fn () => app(StaffAdvances::class)->request(10_000, 'Field trip', $holder)))
        ->toThrow(ValidationException::class, 'advances are not handled in Finance');

    $acme()->forceFill(['advance_max_minor' => null, 'advance_retire_days' => null])->save();
    $checks = collect(app(Readiness::class)->check($acme(), FinanceMode::Integrated))->keyBy('key');
    expect($checks['advances_not_integrated']['passed'])->toBeTrue();
});

it('reconciles revisions: a refused revision sent again is no finding, an unresolved reversal is', function () {
    fiMode('acme', FinanceMode::Integrated);
    [, $expense, $approver] = fiApproved();
    $first = fiRequest();
    fiOutcome($first, 'finance_request.rejected', 1, ['reason' => 'No quote'])->assertCreated();
    $kinds = fn () => app(Reconciliation::class)->run($this->organization('acme')->id)['counts'];
    expect($kinds())->toMatchArray(['rejected_open' => 1]);

    $other = test()->asTenant('acme', fn () => User::factory()->create());
    test()->asTenant('acme', fn () => app(FinanceRequests::class)->resubmit($expense->refresh(), 120_000, 'Quote attached', $other));
    expect($kinds())->not->toHaveKey('rejected_open');

    [, $paidExpense] = fiApproved('acme', 'SEED');
    $paid = fiRequest('acme', $paidExpense);
    fiOutcome($paid, 'finance_request.accepted', 1, ['finance_reference' => 'PR-9'])->assertCreated();
    fiOutcome($paid, 'finance_request.approved', 2, ['approved_minor' => 120_000, 'currency' => 'USD'])->assertCreated();
    fiOutcome($paid, 'finance_payment.completed', 3, ['payment_id' => 'P-9', 'amount_minor' => 120_000, 'currency' => 'USD', 'paid_on' => '2026-11-17'])->assertCreated();
    fiOutcome($paid, 'finance_payment.reversed', 4, ['payment_id' => 'P-9', 'amount_minor' => 120_000, 'currency' => 'USD', 'reason' => 'Wrong payee'])->assertCreated();

    // Taken in, never decided.
    [, $stuck] = fiApproved('acme', 'EDU');
    fiOutcome(fiRequest('acme', $stuck), 'finance_request.accepted', 1, ['finance_reference' => 'PR-10'])->assertCreated();
    $this->travel((int) config('finance_integration.payment_delay_days') + 1)->days();

    expect($kinds())->toMatchArray(['reversed' => 1, 'unresolved_reversal' => 1, 'decision_delayed' => 1])
        ->and($approver)->not->toBeNull();
});

it('frees for new approvals what Finance approved short, and holds refused spending until it is resent or cancelled (Phase 4.1)', function () {
    fiMode('acme', FinanceMode::Integrated);
    // Materials: 10,000.00 approved; 1,200.00 approved spending handed to Finance.
    [$project, $expense] = fiApproved();
    $request = fiRequest();
    $left = fn () => test()->asTenant('acme', fn () => Spending::categoryAvailable($project, CostCategory::Materials));
    $reported = fn () => test()->asTenant('acme', fn () => ProjectFinance::for($project));
    expect($left())->toBe(880_000);

    // Finance approves 800.00 and pays it: it never pays the other 400.00, which can be neither sent again nor cancelled.
    fiOutcome($request, 'finance_request.accepted', 1, ['finance_reference' => 'PR-1'])->assertCreated();
    fiOutcome($request, 'finance_request.approved', 2, ['approved_minor' => 80_000, 'currency' => 'USD'])->assertCreated();
    expect($left())->toBe(920_000)->and($reported()['availableMinor'])->toBe(920_000);
    fiOutcome($request, 'finance_payment.completed', 3, ['payment_id' => 'P-1', 'amount_minor' => 80_000, 'currency' => 'USD', 'paid_on' => '2026-11-17'])->assertCreated();

    expect($request->refresh()->status)->toBe(RequestStatus::Paid)
        // The approvals gate and the reports agree: 800.00 used, not 1,200.00.
        ->and($left())->toBe(920_000)
        ->and($reported()['availableMinor'])->toBe(920_000)
        // Projects' record is not rewritten: Finance's outcome is read, not copied.
        ->and($expense->refresh()->amount_minor)->toBe(120_000)
        ->and($expense->status)->toBe(ExpenseStatus::Approved);
    test()->asTenant('acme', fn () => expect(fn () => Spending::cancel($expense->refresh(), 'Tidy up', User::factory()->create()))->toThrow(ValidationException::class));

    // Refused: it can be sent again, so it stays committed for approvals until resent or cancelled.
    [$seed, $other] = fiApproved('acme', 'SEED');
    fiOutcome(fiRequest('acme', $other), 'finance_request.rejected', 1, ['reason' => 'No supporting quote'])->assertCreated();
    expect(test()->asTenant('acme', fn () => Spending::categoryAvailable($seed, CostCategory::Materials)))->toBe(880_000);
});

it('keeps the budget page, budget lines and grants in step with what Finance settled (Phase 4.1)', function () {
    fiMode('acme', FinanceMode::Integrated);
    [$project, $expense, $approver] = fiApproved();
    $line = test()->asTenant('acme', fn () => BudgetLine::query()->where('project_id', $project->id)->firstOrFail());
    test()->asTenant('acme', fn () => Expense::query()->whereKey($expense->id)->update(['budget_line_key' => $line->line_key]));
    $request = fiRequest();
    fiOutcome($request, 'finance_request.accepted', 1, ['finance_reference' => 'PR-2'])->assertCreated();
    fiOutcome($request, 'finance_request.approved', 2, ['approved_minor' => 100_000, 'currency' => 'USD'])->assertCreated();

    expect(test()->asTenant('acme', fn () => Budgets::usedByLine($project)))->toBe([$line->line_key => 100_000])
        ->and(test()->asTenant('acme', fn () => Budgets::lineAvailable($project, $line->line_key)))->toBe(900_000);

    $viewer = test()->member('acme', ['projects.view', 'projects.finance.view']);
    test()->actingAs($viewer)->get(test()->organizationUrl('acme', "projects/{$project->id}/budget"))->assertOk()
        ->assertInertia(fn ($page) => $page->where('byCategory.0.committedMinor', 100_000)
            ->where('versions.0.lines.0.use.committedMinor', 100_000));
});
