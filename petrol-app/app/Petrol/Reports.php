<?php

namespace App\Petrol;

use App\Models\CreditEntry;
use App\Models\Expense;
use App\Models\FuelReceipt;
use App\Models\FuelSale;
use App\Models\Shift;
use App\Models\Tank;
use App\Support\BusinessDate;

/**
 * Figures for the dashboard and the reports page. They read posted rows only.
 */
class Reports
{
    /**
     * @return array{count: int, total_minor: int, milli: int}
     */
    public function todaySales(string $branchId): array
    {
        return $this->salesBetween($branchId, BusinessDate::today(), BusinessDate::today());
    }

    public function lowTanks(string $branchId, int $percent): int
    {
        return Tank::query()
            ->where('branch_id', $branchId)
            ->where('status', Tank::ACTIVE)
            ->get()
            ->filter(fn (Tank $tank) => $tank->isLow($percent))
            ->count();
    }

    public function pendingApprovals(string $branchId): int
    {
        return Shift::query()
            ->where('branch_id', $branchId)
            ->whereIn('status', [Shift::CLOSING_SUBMITTED, Shift::UNDER_REVIEW])
            ->count();
    }

    public function creditOutstanding(): int
    {
        return (int) CreditEntry::query()->sum('amount_minor');
    }

    /**
     * @return array<string, mixed>
     */
    public function period(string $branchId, string $from, string $to, int $lowPercent): array
    {
        $sales = $this->salesBetween($branchId, $from, $to);
        [$start, $end] = BusinessDate::range($from, $to);

        $rows = FuelSale::query()
            ->with(['payments', 'product', 'nozzle', 'attendant'])
            ->where('branch_id', $branchId)
            ->where('status', FuelSale::POSTED)
            ->whereBetween('sold_at', [$start, $end])
            ->get();

        $cost = (int) $rows->sum('cost_minor');
        $expenses = (int) Expense::query()
            ->where('branch_id', $branchId)
            ->whereDate('incurred_on', '>=', $from)
            ->whereDate('incurred_on', '<=', $to)
            ->sum('amount_minor');

        $byNozzle = [];
        $byAttendant = [];
        foreach ($rows as $sale) {
            $byNozzle[$sale->nozzle->code] = ($byNozzle[$sale->nozzle->code] ?? 0) + $sale->total_minor;
            $byAttendant[$sale->attendant->name] = ($byAttendant[$sale->attendant->name] ?? 0) + $sale->total_minor;
        }

        $receipts = FuelReceipt::query()
            ->where('branch_id', $branchId)
            ->whereBetween('received_at', [$start, $end])
            ->get(['number', 'measured_milli', 'discrepancy_milli', 'total_minor']);

        $shifts = Shift::query()
            ->where('branch_id', $branchId)
            ->where('status', Shift::APPROVED)
            ->whereDate('business_date', '>=', $from)
            ->whereDate('business_date', '<=', $to)
            ->get(['id', 'number', 'cash_difference_minor', 'expected_cash_minor']);

        return [
            'sales' => $sales,
            'cost_minor' => $cost,
            'expenses_minor' => $expenses,
            'profit_minor' => $sales['total_minor'] - $cost - $expenses,
            'by_nozzle' => $byNozzle,
            'by_attendant' => $byAttendant,
            'receipts' => $receipts,
            'shifts' => $shifts,
            'tanks' => Tank::query()->with('product')->where('branch_id', $branchId)->orderBy('code')->get(),
            'low_percent' => $lowPercent,
        ];
    }

    /**
     * @return array{count: int, total_minor: int, milli: int}
     */
    private function salesBetween(string $branchId, string $from, string $to): array
    {
        [$start, $end] = BusinessDate::range($from, $to);
        $query = FuelSale::query()
            ->where('branch_id', $branchId)
            ->where('status', FuelSale::POSTED)
            ->whereBetween('sold_at', [$start, $end]);

        return [
            'count' => (clone $query)->count(),
            'total_minor' => (int) (clone $query)->sum('total_minor'),
            'milli' => (int) (clone $query)->sum('quantity_milli'),
        ];
    }
}
