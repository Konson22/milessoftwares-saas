<?php

namespace App\Http\Controllers;

use App\Actions\Orders;
use App\Actions\Tills;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Concerns\WorksInBranch;
use App\Models\DiningTable;
use App\Models\KitchenTicket;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderLine;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The floor of the branch being worked in, and its orders: taken at the
 * table, sent to the kitchen, billed at the cashier.
 */
class OrderController extends Controller
{
    use WorksInBranch;

    public function __construct(private readonly Orders $orders) {}

    /**
     * Every table of the branch, free or with its order, and the takeaways
     * still open.
     */
    public function floor(): Response
    {
        $open = Order::query()
            ->where('branch_id', $this->branchId())
            ->where('status', Order::OPEN)
            ->with('opener:id,name')
            ->withExists(['tickets as has_ready' => fn ($query) => $query->where('status', KitchenTicket::READY)])
            ->orderBy('opened_at')
            ->get();
        $byTable = $open->whereNotNull('dining_table_id')->keyBy('dining_table_id');

        $tables = DiningTable::query()
            ->where('branch_id', $this->branchId())
            ->where('active', true)
            ->orderBy('area')
            ->orderBy('name')
            ->get();

        return Inertia::render('floor', [
            'tables' => $tables->map(fn (DiningTable $table) => [
                'id' => $table->id,
                'name' => $table->name,
                'area' => $table->area,
                'seats' => $table->seats,
                'order' => ($order = $byTable->get($table->id)) === null ? null : $this->card($order),
            ]),
            'takeaways' => $open->where('service', Order::TAKEAWAY)->values()->map(fn (Order $order) => $this->card($order)),
        ]);
    }

    /**
     * The branch's orders of a day, open, paid and cancelled.
     */
    public function index(Request $request): Response
    {
        $day = CarbonImmutable::parse(is_string($request->query('day')) ? $request->query('day') : 'today')->startOfDay();

        $orders = Order::query()
            ->where('branch_id', $this->branchId())
            ->whereBetween('opened_at', [$day, $day->endOfDay()])
            ->with(['table:id,name', 'opener:id,name', 'bill:id,order_id,number,total_minor,status'])
            ->latest('opened_at')
            ->get();

        return Inertia::render('orders/index', [
            'day' => $day->toDateString(),
            'orders' => $orders->map(fn (Order $order) => [
                'id' => $order->id,
                'number' => $order->number,
                'service' => $order->service,
                'table' => $order->table?->name,
                'customerName' => $order->customer_name,
                'status' => $order->status,
                'openedAt' => $order->opened_at->toIso8601String(),
                'openedBy' => $order->opener->name,
                'bill' => $order->bill === null ? null : ['id' => $order->bill->id, 'number' => $order->bill->number, 'totalMinor' => $order->bill->total_minor, 'status' => $order->bill->status],
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'service' => ['required', Rule::in([Order::DINE_IN, Order::TAKEAWAY])],
            'dining_table_id' => ['nullable', 'uuid', 'required_if:service,'.Order::DINE_IN],
            'guests' => ['nullable', 'integer', 'min:1', 'max:100'],
            'customer_name' => ['nullable', 'string', 'max:255'],
        ]);

        $order = $this->orders->open(
            $this->member($request),
            $this->branchId(),
            $validated['service'],
            $validated['service'] === Order::DINE_IN ? $validated['dining_table_id'] : null,
            isset($validated['guests']) ? (int) $validated['guests'] : null,
            $validated['customer_name'] ?? null,
        );

        return to_route('orders.show', $order);
    }

    public function show(Request $request, Order $order, Tills $tills): Response
    {
        $this->ensureHere($order->branch_id);
        $order->load(['table:id,name', 'opener:id,name', 'bill:id,order_id,number,total_minor,status', 'lines.item:id,name,kind', 'lines.ticket:id,number,status', 'lines.voider:id,name']);

        return Inertia::render('orders/show', [
            'order' => [
                'id' => $order->id,
                'number' => $order->number,
                'service' => $order->service,
                'table' => $order->table === null ? null : ['id' => $order->table->id, 'name' => $order->table->name],
                'guests' => $order->guests,
                'customerName' => $order->customer_name,
                'status' => $order->status,
                'openedAt' => $order->opened_at->toIso8601String(),
                'openedBy' => $order->opener->name,
                'cancelReason' => $order->cancel_reason,
                'bill' => $order->bill === null ? null : ['id' => $order->bill->id, 'number' => $order->bill->number, 'totalMinor' => $order->bill->total_minor, 'status' => $order->bill->status],
                'lines' => $order->lines->map(fn (OrderLine $line) => [
                    'id' => $line->id,
                    'name' => $line->item->name,
                    'kind' => $line->item->kind,
                    'quantity' => $line->quantity,
                    'note' => $line->note,
                    // new, preparing, ready, served, voided
                    'status' => $line->voided() ? 'voided' : ($line->ticket->status ?? 'new'),
                    'ticket' => $line->ticket?->number,
                    'voidReason' => $line->void_reason,
                    'voidedBy' => $line->voider?->name,
                ]),
            ],
            'bill' => $this->orders->bill($order),
            'menu' => $order->isOpen() ? $this->menu() : [],
            'freeTables' => $order->isOpen() && $order->service === Order::DINE_IN
                ? DiningTable::query()->where('branch_id', $order->branch_id)->where('active', true)->whereDoesntHave('openOrder')->orderBy('name')->get(['id', 'name'])
                : [],
            'paymentMethods' => PaymentMethod::options(),
            'tillOpen' => $tills->openFor($this->member($request), $order->branch_id) !== null,
            'can' => [
                'void' => $this->canHere('restaurant.orders.void'),
                'pay' => $this->canHere('restaurant.bills.take'),
            ],
        ]);
    }

    public function addLines(Request $request, Order $order): RedirectResponse
    {
        $this->ensureHere($order->branch_id);
        $validated = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.item_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'lines.*.note' => ['nullable', 'string', 'max:160'],
        ]);

        $this->orders->add($order, $this->member($request), array_values(array_map(fn (array $line) => [
            'item_id' => (string) $line['item_id'],
            'quantity' => (int) $line['quantity'],
            'note' => $line['note'] ?? null,
        ], $validated['lines'])));

        return back();
    }

    public function removeLine(Order $order, OrderLine $line): RedirectResponse
    {
        $this->ensureHere($order->branch_id);
        $this->orders->remove($order, $line);

        return back();
    }

    public function send(Request $request, Order $order): RedirectResponse
    {
        $this->ensureHere($order->branch_id);
        $ticket = $this->orders->send($order, $this->member($request));

        return back()->with('success', "Sent to the kitchen on ticket {$ticket->number}.");
    }

    public function voidLine(Request $request, Order $order, OrderLine $line): RedirectResponse
    {
        $this->ensureHere($order->branch_id);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $this->orders->void($order, $line, $this->member($request), $validated['reason']);

        return back()->with('success', 'Voided: it will not be billed.');
    }

    public function move(Request $request, Order $order): RedirectResponse
    {
        $this->ensureHere($order->branch_id);
        $validated = $request->validate(['dining_table_id' => ['required', 'uuid']]);
        $moved = $this->orders->move($order, $validated['dining_table_id']);

        return back()->with('success', "Order {$moved->number} moved to {$moved->table?->name}.");
    }

    public function pay(Request $request, Order $order): RedirectResponse
    {
        $this->ensureHere($order->branch_id);
        $validated = $request->validate([
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'payment_reference' => ['nullable', 'string', 'max:64'],
            'amount_tendered' => ['nullable', 'string', Money::rule()],
        ]);

        $paid = $this->orders->pay(
            $order,
            $this->member($request),
            PaymentMethod::from($validated['payment_method']),
            isset($validated['amount_tendered']) && $validated['amount_tendered'] !== '' ? Money::toMinor($validated['amount_tendered']) : null,
            $validated['payment_reference'] ?? null,
        );
        $bill = $paid->bill()->firstOrFail();

        return to_route('orders.show', $paid)->with(
            'success',
            "Bill {$bill->number} paid".($bill->change_minor ? ', change '.Money::format($bill->change_minor) : '').'.',
        );
    }

    public function cancel(Request $request, Order $order): RedirectResponse
    {
        $this->ensureHere($order->branch_id);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        // What the kitchen has is voided by cancelling: the same permission.
        abort_if($this->orders->hasLiveTickets($order) && ! $this->canHere('restaurant.orders.void'), 403);

        $cancelled = $this->orders->cancel($order, $this->member($request), $validated['reason']);

        return to_route('floor')->with('success', "Order {$cancelled->number} cancelled.");
    }

    /**
     * @return array<string, mixed>
     */
    private function card(Order $order): array
    {
        return [
            'id' => $order->id,
            'number' => $order->number,
            'guests' => $order->guests,
            'customerName' => $order->customer_name,
            'openedAt' => $order->opened_at->toIso8601String(),
            'openedBy' => $order->opener->name,
            'totalMinor' => $this->orders->bill($order)['total_minor'],
            'hasReady' => (bool) $order->getAttribute('has_ready'),
        ];
    }

    /**
     * What can be ordered, section by section.
     *
     * @return list<array{id: string, name: string, kind: string, section: string|null, priceMinor: int}>
     */
    private function menu(): array
    {
        return array_values(MenuItem::query()
            ->where('active', true)
            ->orderBy('section')
            ->orderBy('name')
            ->get(['id', 'name', 'kind', 'section', 'price_minor'])
            ->map(fn (MenuItem $item) => [
                'id' => $item->id,
                'name' => $item->name,
                'kind' => $item->kind,
                'section' => $item->section,
                'priceMinor' => $item->price_minor,
            ])
            ->all());
    }
}
