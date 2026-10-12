<?php

namespace App\Http\Controllers;

use App\Enums\PrescriptionStatus;
use App\Models\Batch;
use App\Models\Medicine;
use App\Models\Prescription;
use App\Models\Sale;
use App\Pharmacy\CurrentBranch;
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

            $sales = ['count' => (clone $todays)->count(), 'totalMinor' => (int) (clone $todays)->sum('total_minor')];
        }

        $stock = null;
        if ($can('pharmacy.stock.view')) {
            $sellable = Batch::query()
                ->where('branch_id', $branch->id())
                ->whereDate('expires_on', '>', $today)
                ->groupBy('medicine_id')
                ->selectRaw('medicine_id, SUM(quantity_on_hand) AS quantity')
                ->pluck('quantity', 'medicine_id');

            $stock = [
                'low' => Medicine::query()->where('active', true)->get(['id', 'reorder_level'])
                    ->filter(fn (Medicine $medicine) => (int) ($sellable[$medicine->id] ?? 0) <= $medicine->reorder_level)
                    ->count(),
                'expiring' => Batch::query()
                    ->where('branch_id', $branch->id())
                    ->where('quantity_on_hand', '>', 0)
                    ->whereDate('expires_on', '>', $today)
                    ->whereDate('expires_on', '<=', $today->addDays(StockController::EXPIRING_DAYS))
                    ->distinct()
                    ->count('medicine_id'),
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
