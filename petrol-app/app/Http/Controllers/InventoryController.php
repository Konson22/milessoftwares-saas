<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SeesAcrossBranches;
use App\Models\FuelProduct;
use App\Models\FuelReceipt;
use App\Models\FuelTransfer;
use App\Models\Station;
use App\Models\Store;
use App\Models\StoreBalance;
use App\Models\StoreProduct;
use App\Models\StoreTransfer;
use App\Models\Tank;
use App\Petrol\Permissions;
use App\Support\Branches;
use App\Support\Volume;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Api\Branch;

/**
 * What the company holds, across its stations: fuel in the tanks, shop and
 * store goods, and what is on the road between stations. Read-only: stock
 * moves on each station's Tanks, Deliveries, Store and Transfers pages.
 * Fuel shows where the member may see tanks; goods where they may see the
 * store.
 */
class InventoryController extends Controller
{
    use SeesAcrossBranches;

    public function index(Branches $branches): Response
    {
        $allowed = $this->branchesWith($branches, [
            Permissions::TANKS_VIEW, Permissions::PURCHASES_VIEW, Permissions::STORE_MANAGE, Permissions::STORE_MOVE, Permissions::REPORTS_VIEW,
        ]);
        $names = $this->namesOf($allowed);
        $fuelIds = $this->anyOf($allowed, [Permissions::TANKS_VIEW, Permissions::PURCHASES_VIEW, Permissions::REPORTS_VIEW]);
        $storeIds = $this->anyOf($allowed, [Permissions::STORE_MANAGE, Permissions::STORE_MOVE, Permissions::REPORTS_VIEW]);

        return Inertia::render('inventory/index', [
            'stations' => array_map(fn (Branch $branch) => ['id' => $branch->id, 'name' => $branch->name], $allowed),
            'fuel' => $fuelIds === [] ? null : $this->fuel($fuelIds, $names),
            'goods' => $storeIds === [] ? null : $this->goods($storeIds, $names),
        ]);
    }

    /**
     * @param  list<string>  $branchIds
     * @param  array<string, string>  $names
     * @return array<string, mixed>
     */
    private function fuel(array $branchIds, array $names): array
    {
        $percents = Station::query()->whereIn('branch_id', $branchIds)->pluck('low_stock_percent', 'branch_id');
        $tanks = Tank::query()->with('product')->whereIn('branch_id', $branchIds)->where('status', Tank::ACTIVE)->orderBy('code')->get();

        $rows = $tanks->map(fn (Tank $tank) => [
            'id' => $tank->id,
            'branchId' => $tank->branch_id,
            'station' => $names[$tank->branch_id] ?? 'Station',
            'code' => $tank->code,
            'name' => $tank->name,
            'product' => $tank->product->name,
            'quantity' => Volume::toLitres($tank->quantity_milli),
            'capacity' => Volume::toLitres($tank->capacity_milli),
            'fill' => $tank->capacity_milli > 0 ? (int) round($tank->quantity_milli * 100 / $tank->capacity_milli) : 0,
            'valueMinor' => Volume::amount($tank->quantity_milli, $tank->average_cost_minor),
            'low' => $tank->isLow((int) ($percents[$tank->branch_id] ?? 15)),
            'water' => (bool) $tank->getAttribute('water_alert'),
        ])->values();

        $products = $tanks->groupBy('fuel_product_id')->map(function ($group) use ($rows) {
            $first = $group->first();
            $held = (int) $group->sum('quantity_milli');
            $capacity = (int) $group->sum('capacity_milli');

            return [
                'name' => $first->product->name,
                'quantity' => Volume::toLitres($held),
                'capacity' => Volume::toLitres($capacity),
                'fill' => $capacity > 0 ? (int) round($held * 100 / $capacity) : 0,
                'valueMinor' => (int) $group->sum(fn (Tank $tank) => Volume::amount($tank->quantity_milli, $tank->average_cost_minor)),
                'tanks' => $group->count(),
                'low' => $rows->whereIn('id', $group->pluck('id'))->where('low', true)->count(),
                'stations' => $group->pluck('branch_id')->unique()->count(),
            ];
        })->sortBy('name')->values();

        $productNames = FuelProduct::query()->pluck('name', 'id');
        $moving = FuelTransfer::query()->where('status', FuelTransfer::DISPATCHED)
            ->where(fn ($query) => $query->whereIn('branch_id', $branchIds)->orWhereIn('destination_branch_id', $branchIds))
            ->get()->map(fn (FuelTransfer $transfer) => [
                'id' => $transfer->id,
                'product' => $productNames[$transfer->fuel_product_id] ?? 'Fuel',
                'quantity' => Volume::toLitres((int) ($transfer->dispatched_milli ?? $transfer->requested_milli)),
                'from' => $names[$transfer->branch_id] ?? 'Another station',
                'to' => $transfer->destination_branch_id === null ? ($names[$transfer->branch_id] ?? 'Another station') : ($names[$transfer->destination_branch_id] ?? 'Another station'),
            ])->values();

        return [
            'tanks' => $rows,
            'products' => $products,
            'totalLitres' => Volume::toLitres((int) $tanks->sum('quantity_milli')),
            'totalCapacity' => Volume::toLitres((int) $tanks->sum('capacity_milli')),
            'totalValueMinor' => (int) $rows->sum('valueMinor'),
            'inTransit' => $moving,
            'deliveriesWaiting' => FuelReceipt::query()->whereIn('branch_id', $branchIds)->where('status', FuelReceipt::PENDING)->count(),
        ];
    }

    /**
     * @param  list<string>  $branchIds
     * @param  array<string, string>  $names
     * @return array<string, mixed>
     */
    private function goods(array $branchIds, array $names): array
    {
        $stores = Store::query()->whereIn('branch_id', $branchIds)->get(['id', 'branch_id', 'name'])->keyBy('id');
        $balances = StoreBalance::query()->whereIn('store_id', $stores->keys())->get();
        $products = StoreProduct::query()->whereIn('id', $balances->pluck('product_id')->unique())->orderBy('name')->get()->keyBy('id');

        $rows = $products->map(function (StoreProduct $product) use ($balances, $stores, $names) {
            $mine = $balances->where('product_id', $product->id);
            $byStation = [];
            $low = 0;
            foreach ($mine as $balance) {
                $branchId = $stores[$balance->store_id]->branch_id;
                $byStation[$branchId] = ($byStation[$branchId] ?? 0) + $balance->quantity_milli;
                if ($product->reorder_milli !== null && $balance->quantity_milli <= $product->reorder_milli) {
                    $low++;
                }
            }

            return [
                'id' => $product->id,
                'name' => $product->name,
                'code' => $product->code,
                'unit' => $product->unit,
                'reorder' => $product->reorder_milli === null ? null : Volume::toLitres($product->reorder_milli),
                'quantity' => Volume::toLitres((int) $mine->sum('quantity_milli')),
                'valueMinor' => (int) $mine->sum(fn (StoreBalance $balance) => Volume::amount($balance->quantity_milli, $balance->average_cost_minor)),
                'low' => $low,
                'byStation' => collect($byStation)->map(fn (int $milli, string $branchId) => [
                    'branchId' => $branchId,
                    'station' => $names[$branchId] ?? 'Station',
                    'quantity' => Volume::toLitres($milli),
                    'low' => $product->reorder_milli !== null && $milli <= $product->reorder_milli,
                ])->sortBy('station')->values(),
            ];
        })->values();

        $branchOfStore = Store::query()->pluck('branch_id', 'id');
        $productNames = StoreProduct::query()->pluck('name', 'id');
        $moving = StoreTransfer::query()->where('status', StoreTransfer::DISPATCHED)
            ->where(fn ($query) => $query->whereIn('source_store_id', $stores->keys())->orWhereIn('destination_store_id', $stores->keys()))
            ->get()->map(fn (StoreTransfer $transfer) => [
                'id' => $transfer->id,
                'product' => $productNames[$transfer->product_id] ?? 'Item',
                'quantity' => Volume::toLitres((int) ($transfer->dispatched_milli ?? $transfer->requested_milli)),
                'from' => $names[$branchOfStore[$transfer->source_store_id] ?? ''] ?? 'Another station',
                'to' => $names[$branchOfStore[$transfer->destination_store_id] ?? ''] ?? 'Another station',
            ])->values();

        return [
            'items' => $rows,
            'stores' => $stores->count(),
            'totalValueMinor' => (int) $rows->sum('valueMinor'),
            'lowCount' => (int) $rows->sum('low'),
            'inTransit' => $moving,
        ];
    }

    /**
     * @param  list<Branch>  $branches
     * @param  list<string>  $permissions
     * @return list<string>
     */
    private function anyOf(array $branches, array $permissions): array
    {
        $ids = [];
        foreach ($permissions as $permission) {
            $ids = array_merge($ids, $this->branchIdsAllowing($branches, $permission));
        }

        return array_values(array_unique($ids));
    }
}
