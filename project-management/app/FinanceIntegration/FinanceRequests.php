<?php

namespace App\FinanceIntegration;

use App\Audit\Audit;
use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\FinanceRequest;
use App\Models\FinanceRequestTransition;
use App\Models\Organization;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Support\FinanceEvents;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Projects' side of the Finance contract (docs/finance/finance-projects-contract.md).
 * In integrated mode only:
 *
 *   spending approved (Projects)  -> finance.spending.submitted
 *   spending cancelled (Projects) -> finance.spending.cancelled
 *
 * The request and its event are written in the caller's transaction, so the
 * event exists exactly when the request does. In every other mode nothing
 * here does anything, and spending works as before.
 */
final class FinanceRequests
{
    public const CONTRACT_VERSION = 1;

    public function __construct(private readonly FinanceEvents $events) {}

    /**
     * Refuses Projects' own payment paths (pay, record already paid) where
     * Finance owns payment. Separation of duties: nobody in Projects marks a
     * payment Finance makes as completed; only Finance's outcomes do.
     *
     * @throws ValidationException
     */
    public static function refuseLocalPayment(Organization $organization, ?Expense $expense = null): void
    {
        $owned = FinanceMode::of($organization)->financeOwnsPayment()
            || ($expense !== null && FinanceRequest::query()->where('expense_id', $expense->id)->exists());

        if ($owned) {
            throw ValidationException::withMessages(['expense' => 'Finance pays spending for this organization: the payment is recorded when Finance reports it.']);
        }
    }

    /**
     * Hands approved spending to Finance, in integrated mode. Returns null
     * when the organization is not integrated (nothing is sent).
     */
    public function submit(Expense $expense, Project $project, User $by): ?FinanceRequest
    {
        if (! self::integrated($expense) || self::awaitingGoods($expense)) {
            return null;
        }

        return $this->send($expense, $project, $expense->amount_minor, null, null, $by);
    }

    /**
     * Spending raised by a purchase order goes to Finance only once the
     * order is payable: everything received and the supplier's invoice
     * recorded (Procurement::requirePayable, the rule legacy payment keeps).
     * Approving the order commits the money in Projects; it does not ask
     * Finance to pay. Called when goods arrive and when the invoice is
     * recorded; does nothing until both are done, or once a request exists.
     */
    public function submitIfPayable(PurchaseOrder $order, User $by): ?FinanceRequest
    {
        $expense = Expense::query()->findOrFail($order->expense_id);
        if ($expense->status !== ExpenseStatus::Approved || FinanceRequest::query()->where('expense_id', $expense->id)->exists()) {
            return null;
        }

        return $this->submit($expense, Project::query()->findOrFail($expense->project_id), $by);
    }

    /** An order is behind the spending, and it is not payable yet. */
    private static function awaitingGoods(Expense $expense): bool
    {
        $order = PurchaseOrder::query()->where('expense_id', $expense->id)->first();

        return $order !== null && (! $order->isFullyReceived() || $order->invoice_reference === null);
    }

    /**
     * Sends a request Finance refused (or whose payment it reversed) again,
     * as a new revision under the same correlation id (contract §5.3). The
     * refused revision and Finance's reason stay as they were; the new one
     * says what changed. Nothing is sent while the latest revision is still
     * with Finance, so two revisions are never open at once, and Finance
     * refuses an older revision than one it has (ProjectSpending::received).
     *
     * @throws ValidationException
     */
    public function resubmit(Expense $expense, int $amountMinor, string $note, User $by): FinanceRequest
    {
        return DB::transaction(function () use ($expense, $amountMinor, $note, $by) {
            Expense::query()->whereKey($expense->id)->lockForUpdate()->first();
            $expense->refresh();

            return $this->revise($expense, $amountMinor, $note, $by);
        });
    }

    /**
     * @throws ValidationException
     */
    private function revise(Expense $expense, int $amountMinor, string $note, User $by): FinanceRequest
    {
        if (! self::integrated($expense)) {
            throw ValidationException::withMessages(['expense' => 'Finance does not take spending requests for this organization.']);
        }
        if ($expense->status !== ExpenseStatus::Approved) {
            throw ValidationException::withMessages(['expense' => 'Only approved spending is sent to Finance.']);
        }

        $latest = FinanceRequest::query()->where('expense_id', $expense->id)->orderByDesc('revision')->lockForUpdate()->first();
        if ($latest === null) {
            throw ValidationException::withMessages(['expense' => 'This spending was never sent to Finance.']);
        }
        if (! in_array($latest->status, [RequestStatus::Rejected, RequestStatus::Reversed], true)) {
            throw ValidationException::withMessages(['expense' => 'Finance has revision '.$latest->revision.' ('.$latest->status->label().'): it is sent again only once Finance refused it or reversed its payment.']);
        }

        $netPaid = (int) FinanceRequest::query()->where('expense_id', $expense->id)->get(['paid_minor', 'reversed_minor'])->sum(fn (FinanceRequest $r) => $r->netPaidMinor());
        $left = $expense->amount_minor - $netPaid;
        if ($amountMinor < 1 || $amountMinor > $left) {
            throw ValidationException::withMessages(['amount' => 'Between 1 and what is left of the approved spending ('.Money::format($left, $latest->currency).').']);
        }

        $project = Project::query()->findOrFail($expense->project_id);

        return $this->send($expense, $project, $amountMinor, $latest, $note, $by);
    }

    private function send(Expense $expense, Project $project, int $amountMinor, ?FinanceRequest $previous, ?string $note, User $by): FinanceRequest
    {
        $revision = (int) FinanceRequest::query()->where('expense_id', $expense->id)->max('revision') + 1;
        $id = (string) Str::uuid();
        $now = CarbonImmutable::now();

        $request = FinanceRequest::query()->create([
            'id' => $id,
            'project_id' => $project->id,
            'expense_id' => $expense->id,
            'kind' => FinanceRequest::SPENDING,
            'revision' => $revision,
            // One chain of attempts: every revision keeps the first one's correlation id.
            'correlation_id' => $previous === null ? (string) Str::uuid() : $previous->correlation_id,
            'idempotency_key' => "finance-request:{$id}:r{$revision}",
            'contract_version' => self::CONTRACT_VERSION,
            'currency' => $project->currency,
            'amount_minor' => $amountMinor,
            'status' => RequestStatus::Submitted,
            'submitted_at' => $now,
        ]);
        $facts = ['by' => $by->id];
        if ($previous !== null) {
            $facts += ['supersedes' => $previous->id, 'previous_revision' => $previous->revision, 'note' => $note];
        }
        self::record($request, null, RequestStatus::Submitted, 'finance.spending.submitted', 'projects', null, null, $facts, $now);

        $expense->loadMissing('grant');
        $this->events->send('finance.spending.submitted', "{$request->idempotency_key}:submitted", [
            ...self::references($request),
            'status' => RequestStatus::Submitted->value,
            'project' => ['id' => $project->id, 'code' => $project->code, 'name' => $project->name],
            'expense_id' => $expense->id,
            'category' => $expense->category->value,
            'description' => mb_substr($expense->description, 0, 255),
            'supplier' => $expense->supplier,
            'grant_reference' => $expense->grant?->reference,
            'approvals' => $expense->approvals()->count(),
            ...($previous === null ? [] : ['supersedes_request_id' => $previous->id, 'revision_note' => mb_substr((string) $note, 0, 255)]),
        ], $now);
        Audit::record($previous === null ? 'finance_request.submitted' : 'finance_request.resubmitted', $request, ['expense' => $expense->id, 'revision' => $revision, 'amount_minor' => $request->amount_minor, 'currency' => $request->currency, ...($previous === null ? [] : ['supersedes' => $previous->id, 'note' => $note])], actor: $by);

        return $request;
    }

    private static function integrated(Expense $expense): bool
    {
        $organization = Organization::query()->findOrFail($expense->organization_id);

        return FinanceMode::of($organization) === FinanceMode::Integrated && (bool) config('finance_integration.enabled');
    }

    /**
     * Projects cancels spending it handed to Finance. Refused once Finance
     * has paid any of it: a payment is reversed in Finance, never here.
     * Returns whether a request was involved.
     *
     * @throws ValidationException
     */
    public function cancel(Expense $expense, ?string $reason, User $by): bool
    {
        $request = self::current($expense);
        if ($request === null) {
            return false;
        }

        if ($request->paid_minor > 0) {
            throw ValidationException::withMessages(['expense' => 'Finance has paid some of this: a reversal is made in Finance, not here.']);
        }
        if (! $request->status->allows(RequestStatus::CancellationRequested)) {
            // Rejected or already cancelled in Finance: nothing to ask for.
            return true;
        }

        $now = CarbonImmutable::now();
        self::record($request, $request->status, RequestStatus::CancellationRequested, 'finance.spending.cancelled', 'projects', null, null, ['by' => $by->id, 'reason' => $reason], $now);
        $request->forceFill(['status' => RequestStatus::CancellationRequested])->save();

        $this->events->send('finance.spending.cancelled', "{$request->idempotency_key}:cancelled", [
            ...self::references($request),
            'status' => RequestStatus::CancellationRequested->value,
            'reason' => $reason === null ? null : mb_substr($reason, 0, 255),
        ], $now);
        Audit::record('finance_request.cancellation_requested', $request, ['expense' => $expense->id, 'reason' => $reason], actor: $by);

        return true;
    }

    /** The expense's latest request, if it was handed to Finance. */
    public static function current(Expense $expense): ?FinanceRequest
    {
        return FinanceRequest::query()->where('expense_id', $expense->id)->orderByDesc('revision')->first();
    }

    /**
     * The stable references every request message carries.
     *
     * @return array<string, mixed>
     */
    public static function references(FinanceRequest $request): array
    {
        return [
            'originating_app' => 'projects',
            'organization_id' => $request->organization_id,
            'project_id' => $request->project_id,
            'request_id' => $request->id,
            'correlation_id' => $request->correlation_id,
            'idempotency_key' => $request->idempotency_key,
            'version' => $request->revision,
            'contract_version' => $request->contract_version,
            'currency' => $request->currency,
            'amount_minor' => $request->amount_minor,
            'created_at' => $request->submitted_at->toIso8601String(),
        ];
    }

    /**
     * Appends one move to the request's history.
     *
     * @param  array<string, mixed>  $facts
     */
    public static function record(FinanceRequest $request, ?RequestStatus $from, RequestStatus $to, string $cause, string $source, ?string $eventId, ?int $sequence, array $facts, CarbonImmutable $occurredAt): void
    {
        FinanceRequestTransition::query()->create([
            'finance_request_id' => $request->id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'cause' => $cause,
            'source' => $source,
            'event_id' => $eventId,
            'finance_sequence' => $sequence,
            'facts' => $facts,
            'occurred_at' => $occurredAt,
        ]);
    }
}
