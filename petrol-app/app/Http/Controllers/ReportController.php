<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\WorksInBranch;
use App\Models\FuelReceipt;
use App\Models\Shift;
use App\Models\Station;
use App\Models\Tank;
use App\Petrol\Reports;
use App\Support\BusinessDate;
use App\Support\Volume;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    use WorksInBranch;

    public function index(Request $request, Reports $reports): Response
    {
        $today = BusinessDate::today();
        $from = $request->string('from')->toString() ?: substr($today, 0, 8).'01';
        $to = $request->string('to')->toString() ?: $today;

        $station = Station::query()->where('branch_id', $this->branchId())->first();

        return Inertia::render('reports/index', [
            'from' => $from,
            'to' => $to,
            'period' => [
                'sales' => ($period = $reports->period($this->branchId(), $from, $to, $station->low_stock_percent ?? 15))['sales'],
                'costMinor' => $period['cost_minor'],
                'expensesMinor' => $period['expenses_minor'],
                'profitMinor' => $period['profit_minor'],
                'byNozzle' => $period['by_nozzle'],
                'byAttendant' => $period['by_attendant'],
                'receipts' => $period['receipts']->map(fn (FuelReceipt $receipt) => [
                    'number' => $receipt->number,
                    'measured' => Volume::toLitres($receipt->measured_milli),
                    'totalMinor' => $receipt->total_minor,
                ]),
                'shifts' => $period['shifts']->map(fn (Shift $shift) => [
                    'id' => $shift->id,
                    'number' => $shift->number,
                    'differenceMinor' => $shift->cash_difference_minor,
                ]),
                'tanks' => $period['tanks']->map(fn (Tank $tank) => [
                    'code' => $tank->code,
                    'product' => $tank->product->name,
                    'quantity' => Volume::toLitres($tank->quantity_milli),
                    'low' => $tank->isLow($period['low_percent']),
                ]),
            ],
        ]);
    }
}
