<?php

/**
 * Projects side of the E2E run, on e2e_projects. Actions are what people
 * do in Projects (the same actions its pages call), in the organization:
 *
 *   php projects.php mode <slug> <mode> <reason>       projects:finance-mode's path (checks included)
 *   php projects.php expense <slug> <code> <amount_minor> [currency]
 *                                                       a project with an approved budget; spending
 *                                                       requested by one person, approved by another
 *   php projects.php request <slug> <expense_id>        the latest request, its amounts and history
 *   php projects.php cancel <slug> <expense_id> <reason>
 *   php projects.php resubmit <slug> <expense_id> <amount_minor> <note>
 *   php projects.php outbox <slug>                      events to Finance
 *   php projects.php redeliver <id>                     sends an already delivered event again (duplicate delivery)
 *   php projects.php due                                makes every pending event due now, then delivers (time passing)
 *   php projects.php rejections                         refused outcomes, by reason
 *   php projects.php reconcile <slug>
 *   php projects.php figures <slug> <code>              ProjectFinance figures of a project
 *   php projects.php available <slug> <code>            what approvals see left of the materials budget
 *   php projects.php order <slug> <code> <amount_minor> a purchase order (ADR-0020): request, approval, quote,
 *                                                       order, spending approved, all goods received, and two
 *                                                       supplier invoices (40% and 60%) coming to its commitment
 *   php projects.php order-invoice <slug> <expense_id>  one more invoice on that order (refused once with Finance)
 */

require __DIR__.'/lib.php';

$laravel = e2e_boot('projects');

use App\Actions\Procurement;
use App\Actions\Spending;
use App\Enums\BudgetVersionStatus;
use App\Enums\CostCategory;
use App\FinanceIntegration\FinanceMode;
use App\FinanceIntegration\FinanceModes;
use App\FinanceIntegration\FinanceRequests;
use App\FinanceIntegration\Reconciliation;
use App\Models\BudgetVersion;
use App\Models\Expense;
use App\Models\FinanceRequest;
use App\Models\FinanceRequestTransition;
use App\Models\Organization;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Support\ProjectFinance;
use App\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Miles\PlatformClient\Events\Outbox;

[$action, $slug] = [$argv[1] ?? '', $argv[2] ?? ''];

function member(string $name): User
{
    $user = User::query()->where('email', "{$name}@projects.e2e.test")->first();
    if ($user === null) {
        $user = new User;
        $user->forceFill(['id' => (string) Str::uuid(), 'name' => ucfirst($name), 'email' => "{$name}@projects.e2e.test", 'status' => 'active'])->save();
    }

    return $user;
}

/** @return array<string, mixed> */
function describeRequest(Expense $expense): array
{
    $request = FinanceRequests::current($expense);
    if ($request === null) {
        return ['request' => null, 'expense_status' => $expense->refresh()->status->value];
    }

    return [
        'expense_status' => $expense->refresh()->status->value,
        'revisions' => FinanceRequest::query()->where('expense_id', $expense->id)->count(),
        'request_id' => $request->id, 'correlation_id' => $request->correlation_id, 'revision' => $request->revision,
        'status' => $request->status->value, 'finance_reference' => $request->finance_reference,
        'requested' => $request->amount_minor, 'approved' => $request->approved_minor, 'paid' => $request->paid_minor,
        'reversed' => $request->reversed_minor, 'net_paid' => $request->netPaidMinor(),
        'outstanding' => $request->outstandingAuthorizedMinor(), 'open_commitment' => $request->openCommitmentMinor(),
        'sequence' => $request->finance_sequence,
        'history' => FinanceRequestTransition::query()->where('finance_request_id', $request->id)->orderBy('created_at')->orderByRaw('coalesce(finance_sequence, 0)')
            ->get()->map(fn ($t) => "{$t->from_status}>{$t->to_status}".(isset($t->facts['reason']) ? " ({$t->facts['reason']})" : ''))->all(),
    ];
}

/** A project with an approved 100,000.00 materials budget (one line), made once per code. */
function e2eProject(string $code, string $currency): Project
{
    $manager = member('manager');

    return Project::query()->where('code', $code)->first() ?? tap(new Project, function (Project $project) use ($code, $currency, $manager) {
        $project->forceFill([
            'code' => $code, 'name' => "E2E {$code}", 'status' => 'active', 'currency' => $currency,
            'start_on' => '2026-11-01', 'due_on' => '2027-10-31', 'budget_minor' => 10_000_000,
            'created_by' => $manager->id, 'visibility' => 'organization',
        ])->save();
        $version = tap(new BudgetVersion)->forceFill(['project_id' => $project->id, 'number' => 1, 'status' => BudgetVersionStatus::Approved, 'total_minor' => 10_000_000, 'created_by' => $manager->id]);
        $version->save();
        $version->lines()->create(['project_id' => $project->id, 'category' => CostCategory::Materials, 'amount_minor' => 10_000_000]);
    });
}

/**
 * The whole purchase, as people do it in Projects' pages (ADR-0020).
 *
 * @return array<string, mixed>
 */
function e2eOrder(string $code, int $amount, string $currency): array
{
    $project = e2eProject($code, $currency);
    $purchase = Procurement::request($project, ['title' => "Hand pumps {$code}", 'justification' => 'Boreholes.', 'category' => CostCategory::Materials, 'needed_by' => null, 'grant_id' => null],
        [['description' => 'Hand pump', 'quantity' => '10', 'unit' => 'each', 'unit_price_minor' => intdiv($amount, 10)]], member('requester'));
    Procurement::submit($purchase, member('requester'));
    Procurement::approve($purchase->refresh(), null, member('approver'));
    $quote = Procurement::addQuote($purchase->refresh(), ['supplier_id' => Procurement::supplierNamed('Pumps Ltd')->id, 'amount_minor' => $amount, 'reference' => 'Q-1', 'valid_until' => null], null, member('buyer'));
    Procurement::choose($purchase->refresh(), $quote, 'Only supplier with stock.', member('buyer'));
    $order = Procurement::raiseOrder($purchase->refresh(), 'Delivery to the site', member('buyer'));
    Spending::approve($order->expense, null, member('approver'));
    $line = $purchase->lines()->firstOrFail();
    Procurement::receive($order->refresh(), [$line->id => '10'], CarbonImmutable::today(), null, member('storekeeper'));
    $first = intdiv($amount * 4, 10);
    $invoice = fn (string $number, int $minor) => Procurement::recordInvoice($order->refresh(), ['number' => $number, 'amount_minor' => $minor, 'currency' => $currency, 'invoice_on' => CarbonImmutable::today(), 'due_on' => null], null, member('buyer'));
    $invoice("{$code}-A", $first);
    $afterFirst = FinanceRequest::query()->where('expense_id', $order->expense_id)->count();
    $invoice("{$code}-B", $amount - $first);

    return ['expense_id' => $order->expense_id, 'order' => $order->number, 'requests_after_first_invoice' => $afterFirst, ...describeRequest($order->expense)];
}

function attempt(callable $work): array
{
    try {
        return ['ok' => true, 'result' => $work()];
    } catch (ValidationException $e) {
        return ['ok' => false, 'refused' => collect($e->errors())->flatten()->implode(' ')];
    }
}

if ($action === 'redeliver') {
    // The receiver has it already: sent again, it must answer "duplicate".
    DB::table('platform_outbox')->where('id', $slug)->update(['status' => 'pending', 'next_attempt_at' => now()->subSecond()]);
    app(Outbox::class)->deliver($slug);
    e2e_out(DB::table('platform_outbox')->where('id', $slug)->first(['id', 'status', 'last_error']));
}
if ($action === 'due') {
    $ids = DB::table('platform_outbox')->where('status', 'pending')->pluck('id');
    DB::table('platform_outbox')->whereIn('id', $ids)->update(['next_attempt_at' => now()->subSecond()]);
    foreach ($ids as $id) {
        app(Outbox::class)->deliver($id);
    }
    e2e_out(DB::table('platform_outbox')->whereIn('id', $ids)->get(['id', 'event_type', 'status', 'attempts', 'last_error'])->all());
}
if ($action === 'rejections') {
    e2e_out(DB::table('finance_inbox_rejections')->groupBy('reason')->selectRaw('reason, count(*) as n')->pluck('n', 'reason')->all());
}

$organization = Organization::query()->where('slug', $slug)->firstOrFail();

if ($action === 'mode') {
    $outcome = attempt(fn () => app(FinanceModes::class)->change($organization, FinanceMode::from($argv[3]), $argv[4] ?? 'E2E run', 'e2e')->value ?? FinanceMode::of($organization->refresh())->value);
    e2e_out([...$outcome, 'mode' => FinanceMode::of($organization->refresh())->value]);
}

$result = app(CurrentOrganization::class)->run($organization, function () use ($action, $argv, $organization) {
    $expense = fn () => Expense::query()->findOrFail($argv[3]);

    return match ($action) {
        'expense' => (function () use ($argv, $organization) {
            [$code, $amount, $currency] = [$argv[3], (int) $argv[4], $argv[5] ?? $organization->currency];
            $project = e2eProject($code, $currency);
            $spending = Spending::request($project, ['category' => CostCategory::Materials, 'description' => "Hand pumps {$code}", 'supplier' => 'Pumps Ltd', 'amount_minor' => $amount, 'grant_id' => null], member('requester'));
            Spending::approve($spending, null, member('approver'));

            return ['expense_id' => $spending->id, 'project_id' => $project->id, ...describeRequest($spending)];
        })(),
        'request' => describeRequest($expense()),
        'cancel' => attempt(function () use ($expense, $argv) {
            Spending::cancel($expense(), $argv[4], member('approver'));

            return describeRequest($expense());
        }),
        'resubmit' => attempt(fn () => app(FinanceRequests::class)->resubmit($expense(), (int) $argv[4], $argv[5], member('approver'))->revision),
        'outbox' => DB::table('platform_outbox')->where('organization_id', $organization->id)->where('audience', 'finance')
            ->orderBy('created_at')->get(['id', 'event_type', 'source_transaction_id', 'status', 'attempts', 'last_error', 'payload'])
            ->map(fn ($row) => [...(array) $row, 'payload' => json_decode($row->payload, true)])->all(),
        'reconcile' => app(Reconciliation::class)->run($organization->id)['counts'],
        'figures' => ProjectFinance::for(Project::query()->where('code', $argv[3])->firstOrFail()),
        'available' => Spending::categoryAvailable(Project::query()->where('code', $argv[3])->firstOrFail(), CostCategory::Materials),
        'order' => e2eOrder($argv[3], (int) $argv[4], $argv[5] ?? $organization->currency),
        'order-invoice' => attempt(fn () => Procurement::recordInvoice(PurchaseOrder::query()->where('expense_id', $argv[3])->firstOrFail(),
            ['number' => 'LATE-1', 'amount_minor' => 1, 'currency' => Project::query()->findOrFail($expense()->project_id)->currency, 'invoice_on' => CarbonImmutable::today(), 'due_on' => null], null, member('buyer'))->id),
        default => throw new InvalidArgumentException("Unknown action {$action}"),
    };
});

e2e_out($result);
