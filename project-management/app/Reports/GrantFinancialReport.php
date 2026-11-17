<?php

namespace App\Reports;

use App\Actions\Spending;
use App\Enums\ExpenseStatus;
use App\Enums\PurchaseRequestStatus;
use App\Models\Expense;
use App\Models\Grant;
use App\Models\GrantAllocation;
use App\Models\GrantDisbursement;
use App\Models\PurchaseRequest;
use App\Support\Money;

/**
 * Where a grant's money stands, one stage at a time, never added across
 * stages or currencies (Phase 5, M11). Read in the recipient's organization.
 *
 * From the donor (grant currency):
 *   awarded        the grant's amount
 *   agreed         what the donor approved on Tatook (GrantAgreements), if any
 *   scheduled      payments on the schedule
 *   requested      asked of the donor, not decided yet
 *   approved       approved by the donor, not sent
 *   sent           the donor's own record of a transfer; not a receipt
 *   disputed       sent, and contested by the recipient
 *   received       confirmed by the recipient
 *
 * Use, per project the grant is allocated to (project currency):
 *   allocated      the allocation, converted when it was made
 *   procurement    purchase requests charged to it, submitted or approved,
 *                  not ordered yet (estimates)
 *   committed      spending approved, not paid (purchase orders included),
 *                  less what Finance settled short
 *   paid           spending Finance paid: expenditure recognized, on the cash
 *                  basis the books use
 *   available      allocated less procurement, committed and paid
 *
 * The donor's copy keeps the use of funds to what the grant shares with it:
 * commitments (procurement, committed) and spending (paid) each on their own.
 */
final class GrantFinancialReport
{
    /**
     * @return array{currency: string, funding: array<string, int|null>, use: list<array<string, mixed>>}
     */
    public static function build(Grant $grant): array
    {
        $payments = GrantDisbursement::query()->where('grant_id', $grant->id)->get();
        $sum = fn (array $statuses) => (int) $payments->whereIn('status', $statuses)->sum('amount_minor');

        $allocations = GrantAllocation::query()->with('project:id,code,name,currency')->where('grant_id', $grant->id)->get()->sortBy('project.code')->values();
        $spending = Expense::query()->where('grant_id', $grant->id)
            ->whereIn('status', [ExpenseStatus::Approved, ExpenseStatus::Paid])
            ->groupBy('project_id', 'status')->selectRaw('project_id, status, sum(amount_minor) as amount')->get();
        $charged = fn (string $projectId, ExpenseStatus $status) => (int) $spending
            ->filter(fn (Expense $row) => $row->project_id === $projectId && $row->status === $status)
            ->sum(fn (Expense $row) => (int) $row->getAttribute('amount'));
        $short = Spending::settledShort(Expense::query()->where('grant_id', $grant->id));
        $procurement = PurchaseRequest::query()->where('grant_id', $grant->id)
            ->whereIn('status', [PurchaseRequestStatus::Submitted, PurchaseRequestStatus::Approved])
            ->groupBy('project_id')->selectRaw('project_id, sum(estimate_minor) as amount')->pluck('amount', 'project_id');

        return [
            'currency' => $grant->currency,
            'funding' => [
                'awardedMinor' => $grant->amount_minor,
                'agreedMinor' => $grant->agreement_status === 'approved' ? $grant->agreement_amount_minor : null,
                'scheduledMinor' => (int) $payments->sum('amount_minor'),
                'requestedMinor' => $sum(['requested']),
                'approvedMinor' => $sum(['approved']),
                'sentMinor' => $sum(['sent']),
                'disputedMinor' => $sum(['disputed']),
                'receivedMinor' => (int) $payments->whereNotNull('received_on')->sum('amount_minor'),
                'notReceivedMinor' => max(0, $grant->amount_minor - (int) $payments->whereNotNull('received_on')->sum('amount_minor')),
            ],
            'use' => array_values($allocations->map(function (GrantAllocation $allocation) use ($charged, $short, $procurement) {
                $projectId = $allocation->project_id;
                $pipeline = (int) ($procurement[$projectId] ?? 0);
                $committed = max(0, $charged($projectId, ExpenseStatus::Approved) - (int) $short->where('project_id', $projectId)->sum('released_minor'));
                $paid = $charged($projectId, ExpenseStatus::Paid);

                return [
                    'projectId' => $projectId,
                    'project' => "{$allocation->project->code} · {$allocation->project->name}",
                    'currency' => $allocation->project->currency,
                    'allocatedMinor' => $allocation->project_amount_minor,
                    'procurementMinor' => $pipeline,
                    'committedMinor' => $committed,
                    'paidMinor' => $paid,
                    'availableMinor' => $allocation->project_amount_minor - $pipeline - $committed - $paid,
                ];
            })->all()),
        ];
    }

    /**
     * The donor's copy: funding in full (its own money), the use of funds only
     * as far as the grant shares it.
     *
     * @return array{currency: string, funding: array<string, int|null>, use: list<array<string, mixed>>}
     */
    public static function forDonor(Grant $grant): array
    {
        $report = self::build($grant);
        $report['use'] = array_map(fn (array $row) => [
            ...$row,
            'procurementMinor' => $grant->portal_commitments ? $row['procurementMinor'] : null,
            'committedMinor' => $grant->portal_commitments ? $row['committedMinor'] : null,
            'paidMinor' => $grant->portal_spending ? $row['paidMinor'] : null,
            'availableMinor' => $grant->portal_commitments && $grant->portal_spending ? $row['availableMinor'] : null,
        ], $report['use']);

        return $report;
    }

    /**
     * One row per figure, for CsvExport.
     *
     * @param  array{currency: string, funding: array<string, int|null>, use: list<array<string, mixed>>}  $report
     * @return list<array{section: string, line: string, currency: string, amount: string|null}>
     */
    public static function rows(Grant $grant, array $report): array
    {
        $labels = [
            'awardedMinor' => 'Awarded', 'agreedMinor' => 'Agreed with the donor', 'scheduledMinor' => 'Scheduled',
            'requestedMinor' => 'Requested, not decided', 'approvedMinor' => 'Approved, not sent', 'sentMinor' => 'Sent (donor record, not confirmed)',
            'disputedMinor' => 'Disputed', 'receivedMinor' => 'Received (confirmed)', 'notReceivedMinor' => 'Not received yet',
        ];
        $rows = [];
        foreach ($labels as $key => $label) {
            $rows[] = ['section' => "Funding {$grant->reference}", 'line' => $label, 'currency' => $report['currency'], 'amount' => self::major($report['funding'][$key], $report['currency'])];
        }
        $use = ['allocatedMinor' => 'Allocated', 'procurementMinor' => 'Procurement in progress', 'committedMinor' => 'Committed, not paid', 'paidMinor' => 'Paid', 'availableMinor' => 'Available'];
        foreach ($report['use'] as $row) {
            foreach ($use as $key => $label) {
                $rows[] = ['section' => (string) $row['project'], 'line' => $label, 'currency' => (string) $row['currency'], 'amount' => self::major($row[$key], (string) $row['currency'])];
            }
        }

        return $rows;
    }

    /** Each amount in its own currency: the rows mix the grant's and the projects'. Not shared: empty. */
    private static function major(mixed $minor, string $currency): ?string
    {
        return is_int($minor) ? Money::toMajor($minor, $currency) : null;
    }
}
