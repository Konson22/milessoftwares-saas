<?php

namespace App\Http\Controllers;

use App\Modules\Inventory\Http\Controllers\StockController;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Item;
use App\Modules\Pharmacy\Enums\PrescriptionStatus;
use App\Modules\Pharmacy\Models\Prescription;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleReturn;
use App\Support\CurrentBranch;
use App\Support\Industry;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Access\CurrentAccess;

/**
 * The branch at a glance, for the industry being served (its sales, its
 * items): each figure only for members who may see that part here.
 */
class HomeController extends Controller
{
    public function __invoke(CurrentBranch $branch, CurrentAccess $currentAccess, Industry $industry): Response
    {
        $access = $currentAccess->get();
        $can = fn (string $permission) => $access?->allowsIn($permission, branchId: $branch->id()) ?? false;
        $today = CarbonImmutable::today();

        $sales = null;
        if ($can($industry->permission('sales.view'))) {
            $todays = Sale::query()
                ->where('branch_id', $branch->id())
                ->where('application', $industry->code())
                ->where('status', Sale::COMPLETED)
                ->whereBetween('sold_at', [$today, $today->endOfDay()]);

            $returned = (int) SaleReturn::query()
                ->where('branch_id', $branch->id())
                ->whereRelation('sale', 'application', $industry->code())
                ->whereBetween('returned_at', [$today, $today->endOfDay()])
                ->sum('total_minor');

            $sales = ['count' => (clone $todays)->count(), 'totalMinor' => (int) (clone $todays)->sum('total_minor') - $returned];
        }

        $stock = null;
        if ($can($industry->permission('stock.view'))) {
            $sellable = Batch::query()
                ->where('branch_id', $branch->id())
                ->where('status', Batch::AVAILABLE)
                ->unexpired($today)
                ->groupBy('item_id')
                ->selectRaw('item_id, SUM(quantity_on_hand) AS quantity')
                ->pluck('quantity', 'item_id');

            $stock = [
                'low' => Item::query()->where('active', true)->ofKind($industry->itemKinds())->get(['id', 'reorder_level'])
                    ->filter(fn (Item $item) => (int) ($sellable[$item->id] ?? 0) <= $item->reorder_level)
                    ->count(),
                'expiring' => Batch::query()
                    ->where('branch_id', $branch->id())
                    ->whereHas('item', fn ($item) => $item->ofKind($industry->itemKinds()))
                    ->where('quantity_on_hand', '>', 0)
                    ->where('status', Batch::AVAILABLE)
                    ->whereDate('expires_on', '>', $today)
                    ->whereDate('expires_on', '<=', $today->addDays(StockController::EXPIRING_DAYS))
                    ->distinct()
                    ->count('item_id'),
            ];
        }

        $prescriptions = $can($industry->permission('prescriptions.view'))
            ? Prescription::query()->where('branch_id', $branch->id())->where('status', PrescriptionStatus::Recorded)->count()
            : null;

        return Inertia::render($industry->page('home'), [
            'sales' => $sales,
            'stock' => $stock,
            'awaitingVerification' => $prescriptions,
            'expiringDays' => StockController::EXPIRING_DAYS,
        ]);
    }
}
