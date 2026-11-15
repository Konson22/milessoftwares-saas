<?php

/**
 * Finance side of the E2E run, on e2e_finance. Each action is what a
 * Finance person does in Finance (the same services its pages call), in the
 * organization's tenant; Finance's own rules apply (approval limit,
 * separation of duties, currency of the account).
 *
 *   php finance.php staff <slug>                  four people, approval limit 1
 *   php finance.php row <slug> <request_id>       the request as Finance has it
 *   php finance.php prepare <slug> <request_id> <amount_minor>   from the books' bank
 *   php finance.php refuse <slug> <request_id> <reason>
 *   php finance.php review|approve|disburse|withdraw <slug> <request_id>
 *   php finance.php reverse <slug> <request_id> <memo>
 *   php finance.php outbox <slug>                 events to Projects, oldest first
 *   php finance.php deliver <id>...               delivers outbox events, in that order, when due
 *   php finance.php due                           makes every pending event due now (time passing)
 *   php finance.php token <slug> <audience>       an event token from Central, as Finance
 *
 * With E2E_HOLD=1 the outbox records events without delivering them (the
 * queue is switched off), so a scenario can deliver them out of order.
 */

require __DIR__.'/lib.php';

if (getenv('E2E_HOLD') === '1') {
    putenv('QUEUE_CONNECTION=null');
    $_ENV['QUEUE_CONNECTION'] = $_SERVER['QUEUE_CONNECTION'] = 'null';
    $hold = true;
}
$laravel = e2e_boot('finance');
if (isset($hold)) {
    config(['queue.default' => 'null']);
}

use App\Finance\Ledger;
use App\Finance\PaymentRequests;
use App\Finance\ProjectSpending;
use App\Models\Account;
use App\Models\Journal;
use App\Models\PaymentRequest;
use App\Models\ProjectSpendingRequest;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Miles\PlatformClient\Api\PlatformApi;
use Miles\PlatformClient\Events\Outbox;
use Miles\PlatformClient\Tenancy\CurrentTenant;

[$action, $slug] = [$argv[1] ?? '', $argv[2] ?? ''];

function person(string $name): User
{
    $user = User::query()->where('email', "{$name}@finance.e2e.test")->first();
    if ($user === null) {
        $user = new User;
        $user->forceFill(['id' => (string) Illuminate\Support\Str::uuid(), 'name' => ucfirst($name), 'email' => "{$name}@finance.e2e.test"])->save();
    }

    return $user;
}

function spending(string $requestId): ProjectSpendingRequest
{
    return ProjectSpendingRequest::query()->with('paymentRequest')->where('request_id', $requestId)->sole();
}

/** @return array<string, mixed> */
function describe(ProjectSpendingRequest $row): array
{
    $row->refresh()->load('paymentRequest');

    return [
        'status' => $row->status, 'revision' => $row->revision, 'amount_minor' => $row->amount_minor, 'currency' => $row->currency,
        'correlation_id' => $row->correlation_id, 'sequence' => $row->sequence, 'cancellation_requested' => $row->cancellation_requested_at !== null,
        'decision_note' => $row->decision_note,
        'payment_request' => $row->paymentRequest === null ? null : ['number' => $row->paymentRequest->number, 'status' => $row->paymentRequest->status, 'amount_minor' => $row->paymentRequest->amount_minor, 'journal_id' => $row->paymentRequest->journal_id],
    ];
}

function step(callable $work): mixed
{
    try {
        return ['ok' => true, 'result' => $work()];
    } catch (ValidationException $e) {
        return ['ok' => false, 'refused' => collect($e->errors())->flatten()->implode(' ')];
    }
}

if ($action === 'deliver' || $action === 'due') {
    if ($action === 'due') {
        e2e_out(['made_due' => DB::table('platform_outbox')->where('status', 'pending')->update(['next_attempt_at' => now()->subSecond()])]);
    }
    $outbox = app(Outbox::class);
    foreach (array_slice($argv, 2) as $id) {
        $outbox->deliver($id);
    }
    e2e_out(DB::table('platform_outbox')->whereIn('id', array_slice($argv, 2))->get(['id', 'event_type', 'status', 'attempts', 'last_error'])->keyBy('id')->all());
}

$organizationId = e2e_state()['organizations'][$slug] ?? '';
$organization = app(PlatformApi::class)->organization($organizationId);

$result = app(CurrentTenant::class)->run($organization, function () use ($action, $argv, $organizationId) {
    $requestId = $argv[3] ?? '';
    $payments = app(PaymentRequests::class);
    $today = CarbonImmutable::today();

    return match ($action) {
        'staff' => (function () {
            foreach (['officer', 'reviewer', 'approver', 'cashier'] as $name) {
                person($name);
            }
            // Every payment needs approval: Projects' approval decides nothing here.
            Workspace::query()->sole()->forceFill(['payment_approval_threshold_minor' => 1])->save();

            return ['books_currency' => Workspace::query()->sole()->currency];
        })(),
        'row' => describe(spending($requestId)),
        'count' => ProjectSpendingRequest::query()->count(),
        'prepare' => step(function () use ($requestId, $argv, $today) {
            $row = spending($requestId);
            // The books' bank account (in the books' currency).
            $account = Account::query()->where('system_key', Account::BANK)->sole();
            $payment = app(ProjectSpending::class)->prepare($row, $account, (int) $argv[4], $today, person('officer'));

            return ['payment_request' => $payment->number, 'status' => $payment->status];
        }),
        'refuse' => step(fn () => app(ProjectSpending::class)->refuse(spending($requestId), $argv[4], person('reviewer')) ?? describe(spending($requestId))),
        'review' => step(fn () => $payments->review(spending($requestId)->paymentRequest, person('reviewer'))->status),
        'approve' => step(fn () => $payments->approve(spending($requestId)->paymentRequest, person('approver'))->status),
        'disburse' => step(fn () => $payments->disburse(spending($requestId)->paymentRequest, $today, 'E2E-TRANSFER', person('cashier'))->status),
        'withdraw' => step(fn () => $payments->withdraw(spending($requestId)->paymentRequest, person('officer'))->status),
        'reverse' => step(function () use ($requestId, $argv, $today) {
            $journal = Journal::query()->findOrFail(spending($requestId)->paymentRequest?->journal_id);

            return app(Ledger::class)->reverse($journal, $today, $argv[4] ?? 'Reversed in the E2E run')->id;
        }),
        'outbox' => DB::table('platform_outbox')->where('organization_id', $organizationId)->where('audience', 'projects')
            ->orderBy('created_at')->orderBy('source_transaction_id')
            ->get(['id', 'event_type', 'source_transaction_id', 'status', 'attempts', 'last_error', 'payload'])
            ->map(fn ($row) => [...(array) $row, 'payload' => json_decode($row->payload, true)])->all(),
        'token' => app(PlatformApi::class)->eventToken($organizationId, $argv[3]),
        default => throw new InvalidArgumentException("Unknown action {$action}"),
    };
});

e2e_out($result);
