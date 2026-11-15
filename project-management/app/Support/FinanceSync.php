<?php

namespace App\Support;

use App\Actions\Alerts;
use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\UserNotification;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What became of the money events Projects sent to Finance
 * (App\Support\FinanceEvents), read from the platform outbox and checked
 * against the expenses they describe. Read-only: it reports, it never
 * resends, reverses or corrects anything (ADR-0017 §6.6).
 *
 * Sending an event is not Finance posting it. Finance answers each delivery
 * (201 posted, 200 already posted, 401/403/409/422 refused, other later) and
 * the outbox records the outcome (packages/platform-client Outbox).
 *
 * Exceptions need a person:
 *   rejected      Finance refused the content (HTTP 422: not postable).
 *   conflict      the same reference arrived with different content (HTTP 409).
 *   unauthorized  the event token was refused (HTTP 401/403).
 *   gave_up       out of attempts (Finance unreachable or failing).
 *   unsent        an expense paid in Projects with no payment event, in an
 *                 organization whose events reach Finance.
 *   unvoided      Finance posted a payment Projects no longer counts as paid,
 *                 and no void was sent.
 *   void_unposted a void posted for a payment Finance never posted.
 * Delays may clear on their own:
 *   overdue       still not delivered after OVERDUE_HOURS.
 *   retrying      tried, not yet delivered, within OVERDUE_HOURS.
 *
 * Statuses in the outbox: pending, delivered, duplicate (Finance already had
 * it: idempotent, fine), skipped (no Finance), failed.
 */
final class FinanceSync
{
    /** Waiting longer than this is overdue. */
    public const OVERDUE_HOURS = 24;

    /** @deprecated kept for the first report's callers */
    public const STUCK_HOURS = self::OVERDUE_HOURS;

    public const EXCEPTIONS = ['rejected', 'conflict', 'unauthorized', 'gave_up', 'unsent', 'unvoided', 'void_unposted'];

    public const DELAYS = ['overdue', 'retrying'];

    /**
     * Counts by status for each organization.
     *
     * @return array<string, array<string, int>> by organization id
     */
    public static function summary(?string $organizationId = null): array
    {
        $rows = self::outbox($organizationId)
            ->groupBy('organization_id', 'status')
            ->selectRaw('organization_id, status, count(*) as total')
            ->get();

        $summary = [];
        foreach ($rows as $row) {
            $summary[(string) $row->organization_id][(string) $row->status] = (int) $row->total;
        }

        return $summary;
    }

    /**
     * Every finding, exceptions and delays, oldest first. A finding names
     * the event or expense reference, never amounts, suppliers or text.
     *
     * @return list<array{organization_id: string, kind: string, reference: string, event_type: string|null, attempts: int, answer: string|null, occurred_at: string|null}>
     */
    public static function review(?string $organizationId = null): array
    {
        $findings = [];
        $overdueBefore = now()->subHours(self::OVERDUE_HOURS);

        foreach (self::outbox($organizationId)->whereIn('status', ['failed', 'pending'])->orderBy('occurred_at')->orderBy('source_transaction_id')
            ->get(['organization_id', 'event_type', 'source_transaction_id', 'status', 'attempts', 'last_error', 'occurred_at', 'created_at']) as $row) {
            $kind = $row->status === 'failed'
                ? self::failure((string) $row->last_error)
                : (CarbonImmutable::parse((string) $row->created_at)->lt($overdueBefore) ? 'overdue' : ((int) $row->attempts > 0 ? 'retrying' : null));
            if ($kind !== null) {
                $findings[] = self::finding($row->organization_id, $kind, $row->source_transaction_id, $row->event_type, (int) $row->attempts, $row->last_error, $row->occurred_at);
            }
        }

        return [...$findings, ...self::inconsistencies($organizationId)];
    }

    /**
     * Exceptions only: what a person must look at.
     *
     * @return list<array{organization_id: string, kind: string, reference: string, event_type: string|null, attempts: int, answer: string|null, occurred_at: string|null}>
     */
    public static function exceptions(?string $organizationId = null): array
    {
        return array_values(array_filter(self::review($organizationId), fn (array $finding) => in_array($finding['kind'], self::EXCEPTIONS, true)));
    }

    /**
     * The first report's list: refused, out of attempts or overdue events.
     *
     * @return Collection<int, object{organization_id: string, event_type: string, source_transaction_id: string, status: string, attempts: int, last_error: string|null, occurred_at: string}>
     */
    public static function needingAttention(?string $organizationId = null): Collection
    {
        /** @var Collection<int, object{organization_id: string, event_type: string, source_transaction_id: string, status: string, attempts: int, last_error: string|null, occurred_at: string}> */
        return self::outbox($organizationId)
            ->where(fn ($query) => $query->where('status', 'failed')
                ->orWhere(fn ($query) => $query->where('status', 'pending')->where('created_at', '<', now()->subHours(self::OVERDUE_HOURS))))
            ->orderBy('occurred_at')
            ->get(['organization_id', 'event_type', 'source_transaction_id', 'status', 'attempts', 'last_error', 'occurred_at']);
    }

    /**
     * Tells the organization's members who record payments and see
     * commitments (finance.payments.record and finance.commitments.view)
     * what needs a person, once a day. Counts by kind only: no amounts,
     * suppliers or descriptions; the link opens the commitments page they
     * may already see. Run inside the organization. Returns how many were
     * told.
     *
     * @param  array<int, array{kind: string}>  $findings  this organization's
     */
    public static function alert(array $findings): int
    {
        $counts = collect($findings)->countBy('kind');
        $exceptions = $counts->only(self::EXCEPTIONS)->sum();
        $overdue = (int) ($counts['overdue'] ?? 0);
        if ($exceptions === 0 && $overdue === 0) {
            return 0;
        }

        $labels = ['rejected' => 'refused by Finance', 'conflict' => 'in conflict with what Finance has', 'unauthorized' => 'not accepted (access)', 'gave_up' => 'not delivered after every retry',
            'unsent' => 'paid but never sent', 'unvoided' => 'cancelled here, still posted in Finance', 'void_unposted' => 'voided without the payment posted', 'overdue' => 'waiting more than a day'];
        $parts = $counts->only([...self::EXCEPTIONS, 'overdue'])->map(fn (int $count, string $kind) => "{$count} {$labels[$kind]}")->values()->implode(', ');
        $title = $exceptions > 0
            ? "Payment records need attention in Finance: {$exceptions} exception".($exceptions === 1 ? '' : 's')
            : "Payment records are late reaching Finance: {$overdue}";

        // Those who record payments and can open the page the alert links to.
        $viewers = Alerts::holders('finance.commitments.view')->pluck('id')->all();
        $people = Alerts::holders('finance.payments.record')->filter(fn ($user) => in_array($user->id, $viewers, true))->values();

        return Alerts::tell($people, UserNotification::REMINDER, $title,
            ucfirst($parts).'. Nothing was resent or changed.', route('commitments', [], false), null, 'finance-sync:'.now()->toDateString(), email: true);
    }

    private static function failure(string $answer): string
    {
        return match (true) {
            str_starts_with($answer, 'HTTP 422') => 'rejected',
            str_starts_with($answer, 'HTTP 409') => 'conflict',
            str_starts_with($answer, 'HTTP 401'), str_starts_with($answer, 'HTTP 403') => 'unauthorized',
            default => 'gave_up',
        };
    }

    /**
     * Expenses whose state in Projects does not match what Finance was told.
     * Only for organizations whose events reach Finance (some delivered),
     * so one without Finance is not flagged for every payment.
     *
     * @return list<array{organization_id: string, kind: string, reference: string, event_type: string|null, attempts: int, answer: string|null, occurred_at: string|null}>
     */
    private static function inconsistencies(?string $organizationId): array
    {
        $linked = self::outbox($organizationId)->whereIn('status', ['delivered', 'duplicate'])->distinct()->pluck('organization_id')->all();
        $findings = [];

        foreach ($linked as $organization) {
            $events = self::outbox((string) $organization)->where('source_transaction_id', 'like', 'expense:%')
                ->get(['source_transaction_id', 'status'])
                ->mapWithKeys(fn (object $row) => [(string) $row->source_transaction_id => (string) $row->status]);
            $posted = fn (string $key) => in_array($events[$key] ?? null, ['delivered', 'duplicate'], true);

            // Read across tenants on purpose: an operator's report, filtered by organization.
            $expenses = Expense::acrossOrganizations()->where('organization_id', $organization)
                ->whereIn('status', [ExpenseStatus::Paid, ExpenseStatus::Cancelled])
                ->get(['id', 'organization_id', 'status', 'updated_at']);

            foreach ($expenses as $expense) {
                $paid = "expense:{$expense->id}:paid";
                $voided = "expense:{$expense->id}:voided";
                $kind = match (true) {
                    $expense->status === ExpenseStatus::Paid && ! $events->has($paid) => 'unsent',
                    $expense->status !== ExpenseStatus::Paid && $posted($paid) && ! $events->has($voided) => 'unvoided',
                    $posted($voided) && ! $posted($paid) => 'void_unposted',
                    default => null,
                };
                if ($kind !== null) {
                    $findings[] = self::finding($organization, $kind, "expense:{$expense->id}", null, 0, null, $expense->updated_at?->toIso8601String());
                }
            }
        }

        return $findings;
    }

    /**
     * @return array{organization_id: string, kind: string, reference: string, event_type: string|null, attempts: int, answer: string|null, occurred_at: string|null}
     */
    private static function finding(mixed $organization, string $kind, mixed $reference, mixed $type, int $attempts, mixed $answer, mixed $at): array
    {
        return [
            'organization_id' => (string) $organization,
            'kind' => $kind,
            'reference' => (string) $reference,
            'event_type' => $type === null ? null : (string) $type,
            'attempts' => $attempts,
            'answer' => $answer === null ? null : (string) $answer,
            'occurred_at' => $at === null ? null : (string) $at,
        ];
    }

    private static function outbox(?string $organizationId): Builder
    {
        return DB::table('platform_outbox')->where('audience', FinanceEvents::AUDIENCE)
            ->when($organizationId, fn ($query, $id) => $query->where('organization_id', $id));
    }
}
