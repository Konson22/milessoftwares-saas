<?php

namespace App\FinanceIntegration;

use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\FinanceRequest;
use App\Models\Organization;
use App\Support\FinanceEvents;
use App\Support\FinanceSync;
use App\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;

/**
 * Projects' requests against what Finance reported, read-only: nothing is
 * resent, reversed, corrected or deleted. Only the latest revision of a
 * request speaks for the spending: a refused revision sent again is not a
 * finding. Recovery is manual (docs/finance/finance-projects-contract.md
 * §10): a permanently refused event is never retried by itself. Each finding names a request or
 * an event, never an amount owed to a person.
 *
 *   missing_in_finance      the request's event did not reach Finance (failed outbox row)
 *   unacknowledged          Finance has not accepted or rejected it in time
 *   decision_delayed        Finance took it in, and has not approved or refused it in time
 *   rejected_open           Finance rejected it; the spending is still committed in Projects
 *   payment_delayed         Finance approved it, nothing paid after the delay set
 *   paid_after_cancellation Finance paid spending Projects cancelled
 *   expense_out_of_step     the expense is closed in Projects; the request is still open
 *   reversed                Finance took a payment back
 *   unresolved_reversal     reversed in full, the spending still approved in
 *                           Projects, and not sent again (resubmit or release)
 *   missing_in_projects     Finance reported a request or payment Projects does not have
 *   duplicate               the same transaction id came with different content
 *   amount_mismatch         Finance's amount was more than asked, approved or paid
 *   currency_mismatch       Finance's outcome named another currency
 *   invalid_status          an outcome out of turn (transition, sequence)
 *   sync_error              any other refused outcome (token, envelope, mode)
 *   legacy_sync             Projects' own payment events (FinanceSync exceptions)
 */
final class Reconciliation
{
    private const REJECTION_KINDS = [
        'unknown_request' => 'missing_in_projects',
        'unknown_payment' => 'missing_in_projects',
        'conflict' => 'duplicate',
        'duplicate_payment' => 'duplicate',
        'duplicate_reference' => 'duplicate',
        'amount_mismatch' => 'amount_mismatch',
        'currency_mismatch' => 'currency_mismatch',
        'correlation_mismatch' => 'missing_in_projects',
        'invalid_transition' => 'invalid_status',
        'stale_sequence' => 'invalid_status',
        'organization_mismatch' => 'sync_error',
    ];

    public function __construct(private readonly CurrentOrganization $current) {}

    /**
     * @return array{counts: array<string, int>, findings: list<array{organization_id: string|null, kind: string, reference: string, detail: string}>}
     */
    public function run(?string $organizationId = null): array
    {
        $findings = [];

        $organizations = Organization::query()->when($organizationId, fn ($q) => $q->whereKey($organizationId))->get();
        foreach ($organizations as $organization) {
            $findings = [...$findings, ...$this->current->run($organization, fn () => $this->requests($organization))];
            foreach (FinanceSync::exceptions($organization->id) as $exception) {
                $findings[] = self::finding($organization->id, 'legacy_sync', $exception['reference'], $exception['kind']);
            }
        }

        foreach (DB::table('finance_inbox_rejections')->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))
            ->orderBy('received_at')->orderBy('id')->get() as $rejection) {
            $kind = self::REJECTION_KINDS[$rejection->reason] ?? 'sync_error';
            $findings[] = self::finding($rejection->organization_id, $kind, (string) ($rejection->source_transaction_id ?? $rejection->event_id ?? $rejection->id), "{$rejection->reason}: {$rejection->detail}");
        }

        $counts = [];
        foreach ($findings as $finding) {
            $counts[$finding['kind']] = ($counts[$finding['kind']] ?? 0) + 1;
        }
        ksort($counts);

        return ['counts' => $counts, 'findings' => $findings];
    }

    /**
     * @return list<array{organization_id: string|null, kind: string, reference: string, detail: string}>
     */
    private function requests(Organization $organization): array
    {
        $findings = [];
        $ackBefore = now()->subHours((int) config('finance_integration.unacknowledged_hours'));
        $payBefore = now()->subDays((int) config('finance_integration.payment_delay_days'));

        $failed = DB::table('platform_outbox')->where('organization_id', $organization->id)->where('audience', FinanceEvents::AUDIENCE)
            ->where('status', 'failed')->where('event_type', 'like', 'finance.spending.%')->pluck('last_error', 'source_transaction_id');

        $requests = FinanceRequest::query()->with('expense:id,status')->orderBy('submitted_at')->orderBy('revision')->orderBy('id')->get();
        $latest = $requests->groupBy('expense_id')->map(fn ($revisions) => $revisions->max('revision'));
        foreach ($requests as $request) {
            $reference = "finance-request:{$request->id}";
            $expense = $request->expense;
            $isLatest = $latest->get($request->expense_id) === $request->revision;

            foreach (['submitted', 'cancelled'] as $step) {
                $key = "{$request->idempotency_key}:{$step}";
                if ($failed->has($key)) {
                    $findings[] = self::finding($organization->id, 'missing_in_finance', $reference, "finance.spending.{$step}: ".$failed->get($key));
                }
            }

            if ($request->status === RequestStatus::Submitted && $request->submitted_at->lt($ackBefore)) {
                $findings[] = self::finding($organization->id, 'unacknowledged', $reference, 'Not accepted or rejected by Finance since '.$request->submitted_at->toDateTimeString().'.');
            }
            if ($request->status === RequestStatus::Accepted && ($request->last_event_at ?? $request->submitted_at)->lt($payBefore)) {
                $findings[] = self::finding($organization->id, 'decision_delayed', $reference, 'Taken in by Finance, not approved or refused since '.($request->last_event_at ?? $request->submitted_at)->toDateString().'.');
            }
            if ($isLatest && $request->status === RequestStatus::Rejected && $expense->status === ExpenseStatus::Approved) {
                $findings[] = self::finding($organization->id, 'rejected_open', $reference, 'Finance rejected it; release or resubmit the commitment.');
            }
            if ($request->status === RequestStatus::Approved && ($request->last_event_at ?? $request->submitted_at)->lt($payBefore)) {
                $findings[] = self::finding($organization->id, 'payment_delayed', $reference, 'Approved by Finance, not paid since '.($request->last_event_at ?? $request->submitted_at)->toDateString().'.');
            }
            if ($request->paid_minor > 0 && $expense->status === ExpenseStatus::Cancelled) {
                $findings[] = self::finding($organization->id, 'paid_after_cancellation', $reference, 'Finance paid spending cancelled in Projects.');
            } elseif ($request->status->commits() && $expense->status !== ExpenseStatus::Approved) {
                $findings[] = self::finding($organization->id, 'expense_out_of_step', $reference, "The expense is {$expense->status->value} in Projects; the request is {$request->status->value}.");
            }
            if ($request->reversed_minor > 0) {
                $findings[] = self::finding($organization->id, 'reversed', $reference, 'Finance reversed a payment.');
            }
            if ($isLatest && $request->status === RequestStatus::Reversed && $expense->status === ExpenseStatus::Approved) {
                $findings[] = self::finding($organization->id, 'unresolved_reversal', $reference, 'Reversed in full by Finance; send it again or release the commitment.');
            }
        }

        return $findings;
    }

    /**
     * @return array{organization_id: string|null, kind: string, reference: string, detail: string}
     */
    private static function finding(?string $organizationId, string $kind, string $reference, string $detail): array
    {
        return ['organization_id' => $organizationId, 'kind' => $kind, 'reference' => $reference, 'detail' => mb_substr($detail, 0, 300)];
    }
}
