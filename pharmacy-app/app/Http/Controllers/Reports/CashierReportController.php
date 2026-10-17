<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Actions\Tills;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Models\TillSession;
use App\Support\CsvExport;
use App\Support\CurrentBranch;
use App\Support\ReportPeriod;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cashier reconciliation over a period: every till opened in it, what went
 * through it and how the count came out, and per cashier the totals and
 * how much they were over or short.
 */
class CashierReportController extends Controller
{
    public const MAX_TILLS = 500;

    public function __invoke(Request $request, CurrentBranch $branch, Tills $tills): Response|StreamedResponse
    {
        $period = ReportPeriod::fromRequest($request);

        $sessions = TillSession::query()
            ->where('branch_id', $branch->id())
            ->whereBetween('opened_at', $period->between())
            ->with(['opener:id,name'])
            ->latest('opened_at')
            ->limit(self::MAX_TILLS)
            ->get();

        $rows = $sessions->map(function (TillSession $till) use ($tills) {
            $summary = $tills->summary($till);

            return [
                'id' => $till->id,
                'number' => $till->number,
                'cashierId' => $till->opened_by,
                'cashier' => $till->opener->name,
                'openedAt' => $till->opened_at->toIso8601String(),
                'closedAt' => $till->closed_at?->toIso8601String(),
                'status' => $till->status,
                'salesCount' => $summary['salesCount'],
                'takenMinor' => array_sum($summary['takenMinor']),
                'cashTakenMinor' => $summary['takenMinor'][PaymentMethod::Cash->value] ?? 0,
                'cashRefundsMinor' => $summary['cashRefundsMinor'],
                // Fixed at closing; while open, what it should hold so far.
                'expectedCashMinor' => $till->expected_cash_minor ?? $summary['expectedCashMinor'],
                'countedCashMinor' => $till->counted_cash_minor,
                'differenceMinor' => $till->difference(),
                'closingNote' => $till->closing_note,
            ];
        });

        $byCashier = $rows->groupBy('cashierId')->map(fn ($tillsOfCashier) => [
            'id' => (string) $tillsOfCashier->first()['cashierId'],
            'cashier' => (string) $tillsOfCashier->first()['cashier'],
            'tills' => $tillsOfCashier->count(),
            'open' => $tillsOfCashier->where('status', TillSession::OPEN)->count(),
            'salesCount' => (int) $tillsOfCashier->sum('salesCount'),
            'takenMinor' => (int) $tillsOfCashier->sum('takenMinor'),
            'cashRefundsMinor' => (int) $tillsOfCashier->sum('cashRefundsMinor'),
            'overMinor' => (int) $tillsOfCashier->filter(fn (array $row) => ($row['differenceMinor'] ?? 0) > 0)->sum('differenceMinor'),
            'shortMinor' => (int) -$tillsOfCashier->filter(fn (array $row) => ($row['differenceMinor'] ?? 0) < 0)->sum('differenceMinor'),
        ])->sortBy('cashier')->values();

        $name = "{$period->from->toDateString()}-to-{$period->to->toDateString()}";

        return match ($request->query('export')) {
            null => Inertia::render('reports/cashiers', [
                ...$period->toArray(),
                'byCashier' => $byCashier,
                'tills' => $rows->values(),
            ]),
            'cashiers' => CsvExport::download("cashiers-{$name}", ['Cashier' => 'cashier', 'Tills' => 'tills', 'Still open' => 'open', 'Sales' => 'salesCount', 'Taken' => 'takenMinor', 'Cash refunds' => 'cashRefundsMinor', 'Over' => 'overMinor', 'Short' => 'shortMinor'], $byCashier),
            'tills' => CsvExport::download("tills-{$name}", ['Till' => 'number', 'Cashier' => 'cashier', 'Opened' => 'openedAt', 'Closed' => 'closedAt', 'Status' => 'status', 'Sales' => 'salesCount', 'Taken' => 'takenMinor', 'Cash taken' => 'cashTakenMinor', 'Cash refunds' => 'cashRefundsMinor', 'Expected cash' => 'expectedCashMinor', 'Counted cash' => 'countedCashMinor', 'Difference' => 'differenceMinor', 'Note' => 'closingNote'], $rows),
            default => abort(404),
        };
    }
}
