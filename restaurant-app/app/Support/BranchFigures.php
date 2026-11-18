<?php

namespace App\Support;

use App\Models\Bill;
use App\Models\KitchenTicket;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\StockLevel;
use App\Models\TillShift;
use Carbon\CarbonImmutable;
use Miles\PlatformClient\Api\Branch;

/**
 * Figures for several branches side by side, one grouped query per figure
 * (never one per branch): for the all-branches overview and the branch
 * list in management.
 */
final class BranchFigures
{
    /**
     * Each branch side by side: one grouped query per figure, not one per branch.
     *
     * @param  list<Branch>  $branches
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}  $period
     * @return list<array<string, mixed>>
     */
    public function performance(array $branches, array $period, string $currency): array
    {
        $ids = array_map(fn (Branch $branch) => $branch->id, $branches);

        $sales = Bill::query()->whereIn('branch_id', $ids)->where('currency', $currency)->whereBetween('paid_at', $period)
            ->toBase()->selectRaw("branch_id, count(distinct case when status = 'paid' then order_id end) as orders, coalesce(sum(case when status = 'paid' then total_minor else 0 end), 0) as kept, coalesce(sum(total_minor), 0) as total")->groupBy('branch_id')->get()->keyBy('branch_id');
        $refunds = Bill::query()->whereIn('branch_id', $ids)->where('currency', $currency)->where('status', Bill::VOIDED)->whereBetween('voided_at', $period)
            ->toBase()->selectRaw('branch_id, coalesce(sum(total_minor), 0) as total')->groupBy('branch_id')->pluck('total', 'branch_id');
        $tickets = KitchenTicket::query()->whereIn('branch_id', $ids)->whereIn('status', [KitchenTicket::PREPARING, KitchenTicket::READY])
            ->toBase()->selectRaw('branch_id, count(*) as n')->groupBy('branch_id')->pluck('n', 'branch_id');
        $openOrders = Order::query()->whereIn('branch_id', $ids)->where('status', Order::OPEN)
            ->toBase()->selectRaw('branch_id, count(*) as n')->groupBy('branch_id')->pluck('n', 'branch_id');
        $tills = TillShift::query()->whereIn('branch_id', $ids)->where('status', TillShift::OPEN)
            ->toBase()->selectRaw('branch_id, count(*) as n')->groupBy('branch_id')->pluck('n', 'branch_id');
        $low = collect($this->lowStock($ids))->countBy('branchId');

        return array_map(function (Branch $branch) use ($sales, $refunds, $tickets, $openOrders, $tills, $low) {
            $row = $sales->get($branch->id);
            $total = (int) ($row->total ?? 0);
            $orders = (int) ($row->orders ?? 0);

            return [
                'id' => $branch->id,
                'name' => $branch->name,
                'city' => $branch->city,
                'netMinor' => $total - (int) ($refunds[$branch->id] ?? 0),
                'orders' => $orders,
                'averageOrderMinor' => $orders === 0 ? 0 : intdiv((int) ($row->kept ?? 0), $orders),
                'pendingTickets' => (int) ($tickets[$branch->id] ?? 0),
                'openOrders' => (int) ($openOrders[$branch->id] ?? 0),
                'lowStock' => (int) ($low[$branch->id] ?? 0),
                // Trading when a till is open; quiet otherwise.
                'status' => (int) ($tills[$branch->id] ?? 0) > 0 ? 'trading' : 'quiet',
            ];
        }, $branches);
    }

    /**
     * Drinks at or below their reorder level, by branch; below zero means a
     * count is needed (bills took more than was counted in).
     *
     * @param  list<string>  $ids
     * @return list<array{branchId: string, item: string, quantity: int, reorderLevel: int}>
     */
    public function lowStock(array $ids): array
    {
        $items = MenuItem::query()->where('kind', MenuItem::DRINK)->where('active', true)->get(['id', 'name', 'reorder_level'])->keyBy('id');
        $levels = StockLevel::query()->whereIn('branch_id', $ids)->get(['branch_id', 'menu_item_id', 'quantity'])
            ->groupBy('branch_id');

        $low = [];
        foreach ($ids as $branchId) {
            $here = collect($levels->get($branchId, []))->pluck('quantity', 'menu_item_id');
            foreach ($items as $item) {
                $quantity = (int) ($here[$item->id] ?? 0);
                if ($quantity <= $item->reorder_level) {
                    $low[] = ['branchId' => $branchId, 'item' => $item->name, 'quantity' => $quantity, 'reorderLevel' => $item->reorder_level];
                }
            }
        }

        return $low;
    }
}
