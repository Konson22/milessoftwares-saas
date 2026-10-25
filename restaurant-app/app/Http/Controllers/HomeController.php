<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\WorksInBranch;
use App\Models\Bill;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\StockLevel;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The branch at a glance; each figure only for members who may see it.
 */
class HomeController extends Controller
{
    use WorksInBranch;

    public function __invoke(): Response
    {
        $today = now()->startOfDay();

        return Inertia::render('home', [
            'openOrders' => $this->canHere('restaurant.orders.take')
                ? Order::query()->where('branch_id', $this->branchId())->where('status', Order::OPEN)->count()
                : null,
            'takings' => $this->canHere('restaurant.bills.view') ? (function () use ($today) {
                $paid = Bill::query()
                    ->where('branch_id', $this->branchId())
                    ->where('status', Bill::PAID)
                    ->whereBetween('paid_at', [$today, $today->endOfDay()]);

                return ['count' => (clone $paid)->count(), 'totalMinor' => (int) $paid->sum('total_minor')];
            })() : null,
            'lowStock' => $this->canHere('restaurant.stock.view') ? $this->lowStock() : null,
        ]);
    }

    /**
     * Drinks at or below their reorder level here.
     */
    private function lowStock(): int
    {
        $levels = StockLevel::query()->where('branch_id', $this->branchId())->pluck('quantity', 'menu_item_id');

        return MenuItem::query()
            ->where('kind', MenuItem::DRINK)
            ->where('active', true)
            ->get(['id', 'reorder_level'])
            ->filter(fn (MenuItem $item) => (int) ($levels[$item->id] ?? 0) <= $item->reorder_level)
            ->count();
    }
}
