<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\WorksInBranch;
use App\Models\Bill;
use App\Models\Order;
use App\Models\OrderLine;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One day at the branch: what was taken and how, what sold, what was
 * given away or taken back, and who took the money and the orders.
 */
class ReportController extends Controller
{
    use WorksInBranch;

    public function show(Request $request): Response
    {
        $day = $this->day($request);
        $between = [$day, $day->endOfDay()];
        $branch = $this->branchId();

        $kept = Bill::query()
            ->where('branch_id', $branch)
            ->where('status', Bill::PAID)
            ->whereBetween('paid_at', $between)
            ->with(['cashier:id,name', 'order:id,number,opened_by', 'order.opener:id,name'])
            ->get();

        $voided = Bill::query()
            ->where('branch_id', $branch)
            ->where('status', Bill::VOIDED)
            ->whereBetween('voided_at', $between)
            ->with('voider:id,name')
            ->orderBy('voided_at')
            ->get();

        $cancelled = Order::query()
            ->where('branch_id', $branch)
            ->where('status', Order::CANCELLED)
            ->whereBetween('closed_at', $between)
            ->orderBy('closed_at')
            ->get(['id', 'number', 'cancel_reason', 'closed_at']);

        // Lines voided on orders that went on; a cancelled order's lines are counted with it.
        $voidedLines = OrderLine::query()
            ->whereBetween('voided_at', $between)
            ->whereHas('order', fn ($q) => $q->where('branch_id', $branch)->where('status', '!=', Order::CANCELLED))
            ->with(['item:id,name', 'voider:id,name', 'order:id,number'])
            ->orderBy('voided_at')
            ->get();

        // What sold: the lines of the bills kept today, shares included (the last share holds them).
        $sold = OrderLine::query()
            ->whereIn('bill_id', $kept->modelKeys())
            ->whereNull('voided_at')
            ->with('item:id,name')
            ->get()
            ->groupBy('menu_item_id')
            ->map(fn ($lines) => ['name' => $lines->first()->item->name, 'quantity' => (int) $lines->sum('quantity')])
            ->sortByDesc('quantity')
            ->values();

        $total = (int) $kept->sum('total_minor');
        $by = fn (Closure $key, Closure $name) => $this->totals($kept, $key, $name);

        return Inertia::render('reports/day', [
            'day' => $day->toDateString(),
            'takings' => [
                'count' => $kept->count(),
                'totalMinor' => $total,
                'averageMinor' => $kept->isEmpty() ? 0 : intdiv($total, $kept->count()),
                'discountMinor' => (int) $kept->sum('discount_minor'),
                'refundedMinor' => (int) $voided->sum('total_minor'),
            ],
            'byMethod' => $by(fn (Bill $bill) => $bill->payment_method->value, fn (Bill $bill) => $bill->payment_method->label()),
            'byCashier' => $by(fn (Bill $bill) => $bill->paid_by, fn (Bill $bill) => $bill->cashier->name),
            'byWaiter' => $by(fn (Bill $bill) => $bill->order->opened_by, fn (Bill $bill) => $bill->order->opener->name),
            'bestSellers' => $sold->take(15)->all(),
            'discounts' => $kept->where('discount_minor', '>', 0)->values()->map(fn (Bill $bill) => [
                'id' => $bill->id,
                'number' => $bill->number,
                'discountMinor' => $bill->discount_minor,
                'reason' => $bill->discount_reason,
                'by' => $bill->cashier->name,
            ])->all(),
            'voidedBills' => $voided->map(fn (Bill $bill) => [
                'id' => $bill->id,
                'number' => $bill->number,
                'totalMinor' => $bill->total_minor,
                'reason' => $bill->void_reason,
                'by' => $bill->voider?->name,
            ])->all(),
            'cancelledOrders' => $cancelled->map(fn (Order $order) => [
                'id' => $order->id,
                'number' => $order->number,
                'reason' => $order->cancel_reason,
            ])->all(),
            'voidedLines' => $voidedLines->map(fn (OrderLine $line) => [
                'id' => $line->id,
                'order' => $line->order->number,
                'orderId' => $line->order_id,
                'name' => $line->item->name,
                'quantity' => $line->quantity,
                'reason' => $line->void_reason,
                'by' => $line->voider?->name,
            ])->all(),
        ]);
    }

    /**
     * The bills kept, grouped and totalled, the largest first.
     *
     * @param  Collection<int, Bill>  $bills
     * @param  Closure(Bill): string  $key
     * @param  Closure(Bill): string  $name
     * @return list<array{name: string, count: int, totalMinor: int}>
     */
    private function totals(Collection $bills, Closure $key, Closure $name): array
    {
        $groups = [];
        foreach ($bills as $bill) {
            $group = $key($bill);
            $groups[$group] ??= ['name' => $name($bill), 'count' => 0, 'totalMinor' => 0];
            $groups[$group]['count']++;
            $groups[$group]['totalMinor'] += $bill->total_minor;
        }
        usort($groups, fn (array $a, array $b) => $b['totalMinor'] <=> $a['totalMinor']);

        return $groups;
    }
}
