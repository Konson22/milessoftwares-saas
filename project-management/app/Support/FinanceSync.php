<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What became of the money events Projects sent to Finance
 * (App\Support\FinanceEvents), read from the platform outbox. Read-only.
 *
 * Sending an event is not Finance posting it: Finance answers each delivery
 * (201 posted, 200 already posted, 409/422 refused, 503 later) and the outbox
 * records the outcome. Until now nothing in Projects looked at refused or
 * stuck events, so an expense could be paid in Projects and never reach the
 * books without anyone knowing (ADR-0017 §2.4). This is the first, read-only
 * step of reconciliation: it reports, it never resends or corrects.
 *
 * Statuses: pending (not yet tried, or retrying), delivered, duplicate,
 * skipped (the organization has no Finance), failed (refused, or out of
 * attempts: needs a person).
 */
final class FinanceSync
{
    /** Waiting longer than this counts as stuck. */
    public const STUCK_HOURS = 24;

    /**
     * Counts by status for each organization.
     *
     * @return array<string, array<string, int>> by organization id
     */
    public static function summary(?string $organizationId = null): array
    {
        $rows = DB::table('platform_outbox')->where('audience', FinanceEvents::AUDIENCE)
            ->when($organizationId, fn ($query, $id) => $query->where('organization_id', $id))
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
     * Events that need a person: refused by Finance, out of attempts, or
     * still not delivered after STUCK_HOURS.
     *
     * @return Collection<int, object{organization_id: string, event_type: string, source_transaction_id: string, status: string, attempts: int, last_error: string|null, occurred_at: string}>
     */
    public static function needingAttention(?string $organizationId = null): Collection
    {
        /** @var Collection<int, object{organization_id: string, event_type: string, source_transaction_id: string, status: string, attempts: int, last_error: string|null, occurred_at: string}> */
        return DB::table('platform_outbox')->where('audience', FinanceEvents::AUDIENCE)
            ->when($organizationId, fn ($query, $id) => $query->where('organization_id', $id))
            ->where(fn ($query) => $query->where('status', 'failed')
                ->orWhere(fn ($query) => $query->where('status', 'pending')->where('created_at', '<', now()->subHours(self::STUCK_HOURS))))
            ->orderBy('occurred_at')
            ->get(['organization_id', 'event_type', 'source_transaction_id', 'status', 'attempts', 'last_error', 'occurred_at']);
    }
}
