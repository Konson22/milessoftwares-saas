<?php

namespace App\Http\Controllers;

use App\Models\MenuItem;
use App\Models\StockLevel;
use App\Support\Branches;
use App\Support\BranchFigures;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Access\CurrentAccess;
use Miles\PlatformClient\Api\Branch;

/**
 * Management pages beside the dashboard (OverviewController), for those who
 * oversee every branch: the branches, each opening in its own tab, and the
 * drinks held in each.
 */
class ManagementController extends Controller
{
    public function __construct(
        private readonly Branches $branches,
        private readonly BranchFigures $figures,
    ) {}

    /** Every branch open to the member, with today's figures and a way in. */
    public function branches(Request $request, CurrentAccess $access): Response
    {
        $open = $this->overseen();
        $today = [CarbonImmutable::today()->startOfDay(), CarbonImmutable::today()->endOfDay()];
        $figures = collect($this->figures->performance($open, $today, Money::currency()))->keyBy('id');
        $isOwner = $access->get()->isOwner ?? false;

        return Inertia::render('management/branches', [
            'currency' => Money::currency(),
            'branches' => array_map(fn (Branch $branch) => [
                'id' => $branch->id,
                'name' => $branch->name,
                'code' => $branch->code,
                'city' => $branch->city,
                ...$figures->get($branch->id, []),
            ], $open),
            // Branches are set up in the workspace (Structure), for every app.
            'structureUrl' => $isOwner || $this->branches->managesAccess() ? $request->getSchemeAndHttpHost().'/manage/structure' : null,
        ]);
    }

    /** Drinks by branch: what each holds, and what is low or needs a count. */
    public function inventory(): Response
    {
        $open = $this->overseen();
        $ids = array_map(fn (Branch $branch) => $branch->id, $open);
        $levels = StockLevel::query()->whereIn('branch_id', $ids)->get(['branch_id', 'menu_item_id', 'quantity', 'average_cost_minor']);

        $drinks = MenuItem::query()->where('kind', MenuItem::DRINK)->where('active', true)->orderBy('name')
            ->get(['id', 'name', 'unit', 'reorder_level'])
            ->map(function (MenuItem $item) use ($levels, $ids) {
                $here = $levels->where('menu_item_id', $item->id)->keyBy('branch_id');

                return [
                    'id' => $item->id,
                    'name' => $item->name,
                    'unit' => $item->unit,
                    'reorderLevel' => $item->reorder_level,
                    'byBranch' => collect($ids)->mapWithKeys(fn (string $id) => [$id => (int) ($here->get($id)->quantity ?? 0)])->all(),
                    'valueMinor' => (int) $here->sum(fn (StockLevel $level) => max(0, $level->quantity) * $level->average_cost_minor),
                ];
            })->values();

        return Inertia::render('management/inventory', [
            'currency' => Money::currency(),
            'branches' => array_map(fn (Branch $branch) => ['id' => $branch->id, 'name' => $branch->name], $open),
            'drinks' => $drinks,
        ]);
    }

    /**
     * @return list<Branch>
     */
    private function overseen(): array
    {
        abort_unless($this->branches->overview(), 403, 'Only those who oversee every branch see them together.');

        return $this->branches->available();
    }
}
