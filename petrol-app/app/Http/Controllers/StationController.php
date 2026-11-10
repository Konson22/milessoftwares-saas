<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SeesAcrossBranches;
use App\Models\AttendantStation;
use App\Models\Station;
use App\Petrol\Executive;
use App\Petrol\Permissions;
use App\Support\Branches;
use App\Support\BusinessDate;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Access\CurrentAccess;
use Miles\PlatformClient\Api\Branch;

/**
 * Every station of the company in one list: the workspace's branches, which
 * of them run a petrol station and how it is set up, and how each is doing
 * today. Branches themselves are added on the workspace's Structure page;
 * a branch without a station is set up from its own Station settings.
 */
class StationController extends Controller
{
    use SeesAcrossBranches;

    public function index(Request $request, Branches $branches, Executive $executive): Response
    {
        $allowed = $this->branchesWith($branches, [Permissions::REPORTS_VIEW, Permissions::STATIONS_MANAGE]);
        $ids = array_map(fn (Branch $branch) => $branch->id, $allowed);
        $today = BusinessDate::today();
        $board = collect($executive->board($ids, $this->namesOf($allowed), $today, $today))->keyBy('id');
        $stations = Station::query()->whereIn('branch_id', $ids)->get()->keyBy('branch_id');
        $rostered = AttendantStation::query()->whereIn('branch_id', $ids)->where('active', true)
            ->whereHas('attendant', fn ($query) => $query->where('active', true))
            ->toBase()->groupBy('branch_id')->selectRaw('branch_id, count(*) as n')->pluck('n', 'branch_id');
        $manage = $this->branchIdsAllowing($allowed, Permissions::STATIONS_MANAGE);
        $current = $branches->current($request);

        $rows = array_map(function (Branch $branch) use ($board, $stations, $rostered, $manage, $current) {
            $station = $stations->get($branch->id);

            return [
                'id' => $branch->id,
                'name' => $branch->name,
                'branchCode' => $branch->code,
                'city' => $branch->city,
                'current' => $current?->id === $branch->id,
                'canManage' => in_array($branch->id, $manage, true),
                'station' => $station === null ? null : [
                    'code' => $station->code,
                    'active' => $station->active,
                    'main' => $station->is_main,
                    'collectionMode' => $station->collection_mode,
                    'hours' => $station->hours,
                    'lowStockPercent' => $station->low_stock_percent,
                ],
                'rostered' => (int) ($rostered[$branch->id] ?? 0),
                'today' => $board->get($branch->id),
            ];
        }, $allowed);

        $access = app(CurrentAccess::class)->get();

        return Inertia::render('stations/index', [
            'stations' => $rows,
            'today' => $today,
            // The workspace decides who adds branches; offer the way there to those who run stations company-wide.
            'canAddBranch' => $access !== null && ($access->isOwner || $access->branchesFor(Permissions::STATIONS_MANAGE) === null && $access->has(Permissions::STATIONS_MANAGE)),
        ]);
    }
}
