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
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Access\CurrentAccess;

/**
 * The branch at a glance: each figure only for members who may see that
 * part of the pharmacy here.
 */
class HomeController extends Controller
{
    public function __invoke(CurrentBranch $branch, CurrentAccess $currentAccess): Response
    {
        $access = $currentAccess->get();
        $can = fn (string $permission) => $access?->allowsIn($permission, branchId: $branch->id()) ?? false;
        $today = CarbonImmutable::today();

        $sales = null;
        if ($can('pharmacy.sales.view')) {
            $todays = Sale::query()
                ->where('branch_id', $branch->id())
                ->where('status', Sale::COMPLETED)
                ->whereBetween('sold_at', [$today, $today->endOfDay()]);

            $returned = (int) SaleReturn::query()
                ->where('branch_id', $branch->id())
                ->whereBetween('returned_at', [$today, $today->endOfDay()])
                ->sum('total_minor');

            $sales = ['count' => (clone $todays)->count(), 'totalMinor' => (int) (clone $todays)->sum('total_minor') - $returned];
        }

        $stock = null;
        if ($can('pharmacy.stock.view')) {
            $sellable = Batch::query()
                ->where('branch_id', $branch->id())
                ->whereDate('expires_on', '>', $today)
                ->groupBy('item_id')
                ->selectRaw('item_id, SUM(quantity_on_hand) AS quantity')
                ->pluck('quantity', 'item_id');

            $stock = [
                'low' => Item::query()->where('active', true)->get(['id', 'reorder_level'])
                    ->filter(fn (Item $item) => (int) ($sellable[$item->id] ?? 0) <= $item->reorder_level)
                    ->count(),
                'expiring' => Batch::query()
                    ->where('branch_id', $branch->id())
                    ->where('quantity_on_hand', '>', 0)
                    ->whereDate('expires_on', '>', $today)
                    ->whereDate('expires_on', '<=', $today->addDays(StockController::EXPIRING_DAYS))
                    ->distinct()
                    ->count('item_id'),
            ];
        }

        $prescriptions = $can('pharmacy.prescriptions.view')
            ? Prescription::query()->where('branch_id', $branch->id())->where('status', PrescriptionStatus::Recorded)->count()
            : null;

        return Inertia::render('home', [
            'sales' => $sales,
            'stock' => $stock,
            'awaitingVerification' => $prescriptions,
            'expiringDays' => StockController::EXPIRING_DAYS,
        ]);
    }
}
