<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\WorksInBranch;
use App\Models\Shift;
use App\Models\Station;
use App\Petrol\Permissions;
use App\Petrol\Reports;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    use WorksInBranch;

    public function __invoke(Reports $reports): Response
    {
        $branchId = $this->branchId();
        $station = Station::query()->where('branch_id', $branchId)->first();
        $shift = Shift::query()->where('branch_id', $branchId)->where('status', Shift::OPEN)->first();

        return Inertia::render('home', [
            'station' => $station === null ? null : [
                'code' => $station->code,
                'hours' => $station->hours,
                'collectionMode' => $station->collection_mode,
                'active' => $station->active,
                'main' => $station->is_main,
            ],
            'shift' => $shift === null ? null : [
                'id' => $shift->id,
                'number' => $shift->number,
                'status' => $shift->status,
            ],
            'today' => $this->canHere(Permissions::SALES_VIEW) ? $reports->todaySales($branchId) : null,
            'lowTanks' => $station !== null && $this->canHere(Permissions::TANKS_VIEW)
                ? $reports->lowTanks($branchId, $station->low_stock_percent)
                : null,
            'pendingApprovals' => $this->canHere(Permissions::SHIFTS_APPROVE) ? $reports->pendingApprovals($branchId) : null,
            'creditMinor' => $this->canHere(Permissions::CUSTOMERS_VIEW) ? $reports->creditOutstanding() : null,
            'canSetup' => $this->canHere(Permissions::STATIONS_MANAGE),
        ]);
    }
}
