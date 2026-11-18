<?php

namespace App\Http\Controllers;

use App\Actions\Stock;
use App\Enums\PaymentMethod;
use App\Models\Bill;
use App\Models\CashHandover;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\StockMovement;
use App\Models\TillShift;
use App\Support\Branches;
use App\Support\ManagementPeriod;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Tenancy\CurrentTenant;

/**
 * Every branch over a period, for those who oversee them all: sales (what
 * was taken, how, when and what sold, branch by branch) and reports (the
 * controls: discounts, refunds, cancellations and voids, tills over or
 * short, cash not yet handed over, drinks written off).
 *
 * Money is defined as on the dashboard and the day report: sales are bills
 * paid in the period at what was charged, refunds are bills voided in it,
 * net is the difference. Only the workspace currency is added up.
 */
class ConsolidatedController extends Controller
{
    /** How many lines a detail list shows. */
    private const LIST_LIMIT = 100;

    public function sales(Request $request, Branches $branches): Response
    {
        $period = ManagementPeriod::from($request, $branches, 7);
        $ids = $period->ids();
        $between = $period->between();
        $currency = Money::currency();

        $paid = fn () => Bill::query()->whereIn('branch_id', $ids)->where('currency', $currency)->whereBetween('paid_at', $between);
        $refunded = fn () => Bill::query()->whereIn('branch_id', $ids)->where('currency', $currency)->where('status', Bill::VOIDED)->whereBetween('voided_at', $between);

        $sales = $paid()->toBase()
            ->selectRaw("branch_id, coalesce(sum(subtotal_minor), 0) as gross, coalesce(sum(discount_minor), 0) as discounts, coalesce(sum(total_minor), 0) as total, coalesce(sum(case when status = 'paid' then 1 else 0 end), 0) as bills, count(distinct case when status = 'paid' then order_id end) as orders, coalesce(sum(case when status = 'paid' then total_minor else 0 end), 0) as kept")
            ->groupBy('branch_id')->get()->keyBy('branch_id');
        $refunds = $refunded()->toBase()->selectRaw('branch_id, count(*) as bills, coalesce(sum(total_minor), 0) as total')
            ->groupBy('branch_id')->get()->keyBy('branch_id');
        $methods = $paid()->toBase()->selectRaw('branch_id, payment_method, coalesce(sum(total_minor), 0) as total')
            ->groupBy('branch_id', 'payment_method')->get();

        $byBranch = array_map(function (array $branch) use ($sales, $refunds, $methods) {
            $row = $sales->get($branch['id']);
            $total = (int) ($row->total ?? 0);
            $refunded = (int) ($refunds->get($branch['id'])->total ?? 0);
            $orders = (int) ($row->orders ?? 0);
            $cash = (int) $methods->where('branch_id', $branch['id'])->where('payment_method', PaymentMethod::Cash->value)->sum('total');

            return [
                'id' => $branch['id'],
                'name' => $branch['name'],
                'grossMinor' => (int) ($row->gross ?? 0),
                'discountMinor' => (int) ($row->discounts ?? 0),
                'salesMinor' => $total,
                'refundedMinor' => $refunded,
                'netMinor' => $total - $refunded,
                'bills' => (int) ($row->bills ?? 0),
                'orders' => $orders,
                'averageOrderMinor' => $orders === 0 ? 0 : intdiv((int) ($row->kept ?? 0), $orders),
                'cashMinor' => $cash,
                'nonCashMinor' => $total - $cash,
            ];
        }, $period->props()['shownBranches']);

        $byMethod = $methods->groupBy('payment_method')->map(fn ($rows, $method) => [
            'method' => (string) $method,
            'name' => PaymentMethod::tryFrom((string) $method)?->label() ?? (string) $method,
            'byBranch' => $rows->mapWithKeys(fn (object $row) => [(string) $row->branch_id => (int) $row->total])->all(),
            'totalMinor' => (int) $rows->sum('total'),
        ])->sortByDesc('totalMinor')->values()->all();

        return Inertia::render('management/sales', [
            ...$period->props(),
            'currency' => $currency,
            'byBranch' => $byBranch,
            'byMethod' => $byMethod,
            'byDay' => $this->byDay($paid(), $refunded(), $period),
            'bestSellers' => $this->bestSellers($ids, $between, $currency),
        ]);
    }

    public function reports(Request $request, Branches $branches): Response
    {
        $period = ManagementPeriod::from($request, $branches, 7);
        $ids = $period->ids();
        $between = $period->between();
        $names = $period->names();
        $currency = Money::currency();
        $name = fn (string $branchId) => $names[$branchId] ?? '';

        $discounted = Bill::query()->whereIn('branch_id', $ids)->where('currency', $currency)->where('status', Bill::PAID)
            ->where('discount_minor', '>', 0)->whereBetween('paid_at', $between);
        $voided = Bill::query()->whereIn('branch_id', $ids)->where('currency', $currency)->where('status', Bill::VOIDED)->whereBetween('voided_at', $between);
        $cancelled = Order::query()->whereIn('branch_id', $ids)->where('status', Order::CANCELLED)->whereBetween('closed_at', $between);
        $voidedLines = OrderLine::query()->whereBetween('voided_at', $between)
            ->whereHas('order', fn ($query) => $query->whereIn('branch_id', $ids)->where('status', '!=', Order::CANCELLED));
        $tills = TillShift::query()->whereIn('branch_id', $ids)->where('status', TillShift::CLOSED)->whereBetween('closed_at', $between);
        $writeOffs = StockMovement::query()->whereIn('branch_id', $ids)->whereIn('type', [StockMovement::WRITE_OFF, StockMovement::COUNT])
            ->where('quantity', '<', 0)->whereBetween('created_at', $between);
        // Cash still with someone, whenever it was handed over.
        $pending = CashHandover::query()->whereIn('branch_id', $ids)->where('status', CashHandover::PENDING);
        $received = CashHandover::query()->whereIn('branch_id', $ids)->where('status', CashHandover::RECEIVED)->whereBetween('received_at', $between);

        $figures = [
            'discounts' => $this->perBranch($discounted, 'sum(discount_minor)'),
            'refunds' => $this->perBranch($voided, 'sum(total_minor)'),
            'cancelled' => $this->perBranch($cancelled, 'count(*)'),
            'tills' => $this->perBranch($tills, 'sum(counted_cash_minor - expected_cash_minor)'),
            'writeOffs' => $this->perBranch($writeOffs, 'sum(-quantity * unit_cost_minor)'),
            'pending' => $this->perBranch($pending, 'sum(amount_minor)'),
        ];
        $lineCounts = (clone $voidedLines)->join('orders', 'orders.id', '=', 'order_lines.order_id')->toBase()
            ->selectRaw('orders.branch_id, count(*) as n')->groupBy('orders.branch_id')->pluck('n', 'branch_id');
        $tillsShort = (clone $tills)->whereColumn('counted_cash_minor', '!=', 'expected_cash_minor')->toBase()
            ->selectRaw('branch_id, count(*) as n')->groupBy('branch_id')->pluck('n', 'branch_id');

        $byBranch = array_map(fn (array $branch) => [
            'id' => $branch['id'],
            'name' => $branch['name'],
            'discounts' => (int) ($figures['discounts']->get($branch['id'])->n ?? 0),
            'discountMinor' => (int) ($figures['discounts']->get($branch['id'])->total ?? 0),
            'refunds' => (int) ($figures['refunds']->get($branch['id'])->n ?? 0),
            'refundedMinor' => (int) ($figures['refunds']->get($branch['id'])->total ?? 0),
            'cancelledOrders' => (int) ($figures['cancelled']->get($branch['id'])->n ?? 0),
            'voidedLines' => (int) ($lineCounts[$branch['id']] ?? 0),
            'tillsClosed' => (int) ($figures['tills']->get($branch['id'])->n ?? 0),
            'tillsOff' => (int) ($tillsShort[$branch['id']] ?? 0),
            'tillDifferenceMinor' => (int) ($figures['tills']->get($branch['id'])->total ?? 0),
            'writtenOffMinor' => (int) ($figures['writeOffs']->get($branch['id'])->total ?? 0),
            'pendingHandovers' => (int) ($figures['pending']->get($branch['id'])->n ?? 0),
            'pendingHandoverMinor' => (int) ($figures['pending']->get($branch['id'])->total ?? 0),
        ], $period->props()['shownBranches']);

        return Inertia::render('management/reports', [
            ...$period->props(),
            'currency' => $currency,
            'byBranch' => $byBranch,
            'discounts' => (clone $discounted)->with('cashier:id,name')->latest('discount_minor')->limit(self::LIST_LIMIT)->get()->map(fn (Bill $bill) => [
                'id' => $bill->id, 'branchId' => $bill->branch_id, 'branch' => $name($bill->branch_id), 'number' => $bill->number,
                'at' => $bill->paid_at->toIso8601String(), 'amountMinor' => $bill->discount_minor, 'reason' => $bill->discount_reason, 'by' => $bill->cashier->name,
            ])->all(),
            'refunds' => (clone $voided)->with('voider:id,name')->latest('voided_at')->limit(self::LIST_LIMIT)->get()->map(fn (Bill $bill) => [
                'id' => $bill->id, 'branchId' => $bill->branch_id, 'branch' => $name($bill->branch_id), 'number' => $bill->number,
                'at' => $bill->voided_at?->toIso8601String(), 'amountMinor' => $bill->total_minor, 'reason' => $bill->void_reason, 'by' => $bill->voider?->name,
            ])->all(),
            'cancelledOrders' => (clone $cancelled)->latest('closed_at')->limit(self::LIST_LIMIT)->get()->map(fn (Order $order) => [
                'id' => $order->id, 'branchId' => $order->branch_id, 'branch' => $name($order->branch_id), 'number' => $order->number,
                'at' => $order->closed_at?->toIso8601String(), 'reason' => $order->cancel_reason,
            ])->all(),
            'voidedLines' => (clone $voidedLines)->with(['item:id,name', 'voider:id,name', 'order:id,number,branch_id'])->latest('voided_at')->limit(self::LIST_LIMIT)->get()->map(fn (OrderLine $line) => [
                'id' => $line->id, 'branchId' => $line->order->branch_id, 'branch' => $name($line->order->branch_id), 'orderId' => $line->order_id, 'order' => $line->order->number,
                'at' => $line->voided_at?->toIso8601String(), 'item' => $line->item->name, 'quantity' => $line->quantity, 'reason' => $line->void_reason, 'by' => $line->voider?->name,
            ])->all(),
            'tills' => (clone $tills)->whereColumn('counted_cash_minor', '!=', 'expected_cash_minor')->with('opener:id,name')->latest('closed_at')->limit(self::LIST_LIMIT)->get()->map(fn (TillShift $till) => [
                'id' => $till->id, 'branchId' => $till->branch_id, 'branch' => $name($till->branch_id), 'number' => $till->number,
                'at' => $till->closed_at?->toIso8601String(), 'cashier' => $till->opener->name,
                'expectedMinor' => (int) $till->expected_cash_minor, 'countedMinor' => (int) $till->counted_cash_minor,
                'differenceMinor' => (int) $till->counted_cash_minor - (int) $till->expected_cash_minor, 'note' => $till->closing_note,
            ])->all(),
            'handovers' => CashHandover::query()->whereIn('branch_id', $ids)
                ->where(fn ($query) => $query->where('status', CashHandover::PENDING)
                    ->orWhere(fn ($query) => $query->whereBetween('received_at', $between)->whereColumn('received_minor', '!=', 'amount_minor')))
                ->with(['hander:id,name', 'receiver:id,name', 'till:id,number'])->latest('handed_at')->limit(self::LIST_LIMIT)->get()
                ->map(fn (CashHandover $handover) => [...HandoverController::present($handover), 'branchId' => $handover->branch_id, 'branch' => $name($handover->branch_id)])->all(),
            'receivedTotalMinor' => (int) (clone $received)->sum('received_minor'),
            'writeOffs' => (clone $writeOffs)->with(['item:id,name,unit', 'creator:id,name'])->latest('created_at')->limit(self::LIST_LIMIT)->get()->map(fn (StockMovement $movement) => [
                'id' => $movement->id, 'branchId' => $movement->branch_id, 'branch' => $name($movement->branch_id),
                'at' => $movement->created_at->toIso8601String(), 'item' => $movement->item->name, 'quantity' => -$movement->quantity,
                'costMinor' => -$movement->quantity * $movement->unit_cost_minor, 'type' => $movement->type, 'reason' => $movement->reason === null ? null : (Stock::REASONS[$movement->reason] ?? $movement->reason), 'note' => $movement->note, 'by' => $movement->creator->name,
            ])->all(),
        ]);
    }

    /**
     * How many of $query, and $expression added up, branch by branch.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  literal-string  $expression
     * @return Collection<array-key, \stdClass>
     */
    private function perBranch(Builder $query, string $expression)
    {
        return (clone $query)->toBase()->selectRaw('branch_id, count(*) as n, coalesce('.$expression.', 0) as total')->groupBy('branch_id')->get()->keyBy('branch_id');
    }

    /**
     * Net sales day by day and branch by branch, in the application's days.
     *
     * @param  Builder<Bill>  $paid
     * @param  Builder<Bill>  $refunded
     * @return list<array{day: string, byBranch: array<string, int>, netMinor: int}>
     */
    private function byDay($paid, $refunded, ManagementPeriod $period): array
    {
        $days = [];
        for ($day = $period->from; $day <= $period->to; $day = $day->addDay()) {
            $days[$day->toDateString()] = [];
        }
        $add = function (iterable $rows, string $column, int $sign) use (&$days) {
            foreach ($rows as $row) {
                $key = CarbonImmutable::parse($row->{$column})->setTimezone(config('app.timezone'))->toDateString();
                if (isset($days[$key])) {
                    $days[$key][$row->branch_id] = ($days[$key][$row->branch_id] ?? 0) + $sign * (int) $row->total_minor;
                }
            }
        };
        $add($paid->toBase()->select(['branch_id', 'paid_at', 'total_minor'])->cursor(), 'paid_at', 1);
        $add($refunded->toBase()->select(['branch_id', 'voided_at', 'total_minor'])->cursor(), 'voided_at', -1);

        return array_map(fn (string $day, array $byBranch) => ['day' => $day, 'byBranch' => $byBranch, 'netMinor' => array_sum($byBranch)], array_keys($days), array_values($days));
    }

    /**
     * What sold most, by quantity, with each branch's share: the lines of
     * bills still paid (an order paid in shares counts once, as on the day
     * report).
     *
     * @param  list<string>  $ids
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}  $between
     * @return list<array{id: string, name: string, byBranch: array<string, int>, quantity: int}>
     */
    private function bestSellers(array $ids, array $between, string $currency): array
    {
        $rows = OrderLine::query()
            ->join('bills', 'bills.id', '=', 'order_lines.bill_id')
            ->join('menu_items', 'menu_items.id', '=', 'order_lines.menu_item_id')
            // Joined, so no tenant scope applies: name the organization.
            ->where('bills.organization_id', app(CurrentTenant::class)->require()->id)
            ->whereIn('bills.branch_id', $ids)
            ->where('bills.status', Bill::PAID)
            ->where('bills.currency', $currency)
            ->whereBetween('bills.paid_at', $between)
            ->whereNull('order_lines.voided_at')
            ->toBase()
            ->selectRaw('menu_items.id, menu_items.name, bills.branch_id, sum(order_lines.quantity) as quantity')
            ->groupBy('menu_items.id', 'menu_items.name', 'bills.branch_id')
            ->get();

        return array_values($rows->groupBy('id')->map(fn ($lines) => [
            'id' => (string) $lines->first()->id,
            'name' => (string) $lines->first()->name,
            'byBranch' => $lines->mapWithKeys(fn (object $line) => [(string) $line->branch_id => (int) $line->quantity])->all(),
            'quantity' => (int) $lines->sum('quantity'),
        ])->sortByDesc('quantity')->take(25)->values()->all());
    }
}
