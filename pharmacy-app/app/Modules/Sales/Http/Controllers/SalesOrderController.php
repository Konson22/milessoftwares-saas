<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Inventory\Actions\InsufficientStock;
use App\Modules\Inventory\Actions\Stock;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemPack;
use App\Modules\Sales\Actions\CustomerAccounts;
use App\Modules\Sales\Actions\Deliver;
use App\Modules\Sales\Actions\SalesOrders;
use App\Modules\Sales\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\DeliveryNote;
use App\Modules\Sales\Models\DeliveryNoteLine;
use App\Modules\Sales\Models\PriceList;
use App\Modules\Sales\Models\PriceListPrice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use App\Support\CurrentBranch;
use App\Support\Industry;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Access\CurrentAccess;
use Miles\PlatformClient\Tenancy\CurrentTenant;

/**
 * Wholesale quotations and sales orders of the branch being worked in, and
 * the delivery notes that ship and invoice them.
 */
class SalesOrderController extends Controller
{
    public function __construct(
        private readonly CurrentBranch $branch,
        private readonly Industry $industry,
    ) {}

    public function index(Request $request): Response
    {
        $status = SalesOrderStatus::tryFrom($request->string('status')->toString());

        return Inertia::render($this->industry->page('orders/index'), [
            'orders' => SalesOrder::query()
                ->where('branch_id', $this->branch->id())
                ->where('application', $this->industry->code())
                ->when($status !== null, fn ($query) => $query->where('status', $status))
                ->with('customer:id,name,number')
                ->latest()
                ->limit(200)
                ->get()
                ->map(fn (SalesOrder $order) => [
                    'id' => $order->id,
                    'reference' => $order->reference(),
                    'quoteNumber' => $order->quote_number,
                    'customer' => $order->customer->name,
                    'customerReference' => $order->customer_reference,
                    'status' => $order->status->value,
                    'statusLabel' => $order->status->label(),
                    'expired' => $this->expired($order),
                    'validUntil' => $order->valid_until->toDateString(),
                    'totalMinor' => $order->total_minor,
                    'createdAt' => $order->created_at->toIso8601String(),
                ]),
            'filters' => ['status' => $status->value ?? ''],
            'statuses' => array_map(fn (SalesOrderStatus $s) => ['value' => $s->value, 'label' => $s->label()], SalesOrderStatus::cases()),
            'canManage' => $this->allows('orders.manage'),
        ]);
    }

    public function create(CustomerAccounts $accounts): Response
    {
        $customers = Customer::query()
            ->where('application', $this->industry->code())
            ->where('active', true)
            ->orderBy('name')
            ->limit(1000)
            ->get();
        $owed = $accounts->balances($customers);
        $lists = PriceList::query()
            ->where('application', $this->industry->code())
            ->where('active', true)
            ->with('prices')
            ->get();

        return Inertia::render($this->industry->page('orders/create'), [
            'customers' => $customers->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'number' => $customer->number,
                'name' => $customer->name,
                'priceListId' => $customer->price_list_id,
                'creditLimitMinor' => $customer->credit_limit_minor,
                'availableMinor' => max(0, $customer->credit_limit_minor - ($owed[$customer->id] ?? 0)),
            ]),
            'items' => $this->itemOptions(),
            // Each active list's prices, to show what a line will cost
            // before it is saved (the server prices it, PriceLists).
            'priceLists' => $lists->mapWithKeys(fn (PriceList $list) => [$list->id => [
                'name' => $list->name,
                'prices' => $list->prices->map(fn (PriceListPrice $price) => [
                    'itemId' => $price->item_id,
                    'packId' => $price->item_pack_id,
                    'priceMinor' => $price->price_minor,
                ])->values(),
            ]]),
            'maxDiscountPercent' => $this->maxDiscountPercent(),
            'validUntil' => CarbonImmutable::today()->addDays(14)->toDateString(),
        ]);
    }

    public function store(Request $request, SalesOrders $orders): RedirectResponse
    {
        $validated = $request->validate([
            'customer_id' => ['required', 'uuid'],
            'valid_until' => ['required', 'date'],
            'customer_reference' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:500'],
            'confirm' => ['boolean'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.item_id' => ['required', 'uuid', Rule::exists('items', 'id')->where('organization_id', app(CurrentTenant::class)->id())],
            'lines.*.pack_id' => ['nullable', 'uuid'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'lines.*.discount_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);
        $user = $this->user($request);

        $order = DB::transaction(function () use ($orders, $validated, $user) {
            $order = $orders->quote(
                by: $user,
                branchId: $this->branch->id(),
                customerId: (string) $validated['customer_id'],
                lines: array_values(array_map(fn (array $line) => [
                    'item_id' => $line['item_id'],
                    'pack_id' => $line['pack_id'] ?? null,
                    'quantity' => (int) $line['quantity'],
                    'discount_percent' => (int) ($line['discount_percent'] ?? 0),
                ], $validated['lines'])),
                validUntil: CarbonImmutable::parse($validated['valid_until']),
                customerReference: $validated['customer_reference'] ?? null,
                notes: $validated['notes'] ?? null,
                maxDiscountPercent: $this->maxDiscountPercent(),
            );

            if ($validated['confirm'] ?? false) {
                $orders->confirm($order, $user);
            }

            return $order;
        });

        return to_route($this->industry->route('orders.show'), $order)->with('success', $order->order_number === null
            ? "Quotation {$order->quote_number} saved."
            : "Order {$order->order_number} confirmed.");
    }

    public function show(SalesOrder $order, CustomerAccounts $accounts, Stock $stock): Response
    {
        $this->ensureHere($order);
        $order->load(['customer', 'priceList:id,name', 'creator:id,name', 'lines.item', 'deliveries.sale:id,number,total_minor', 'deliveries.deliverer:id,name']);
        $owed = $accounts->balance($order->customer);

        return Inertia::render($this->industry->page('orders/show'), [
            'order' => [
                'id' => $order->id,
                'reference' => $order->reference(),
                'quoteNumber' => $order->quote_number,
                'orderNumber' => $order->order_number,
                'status' => $order->status->value,
                'statusLabel' => $order->status->label(),
                'expired' => $this->expired($order),
                'validUntil' => $order->valid_until->toDateString(),
                'customerReference' => $order->customer_reference,
                'notes' => $order->notes,
                'priceList' => $order->priceList?->name,
                'totalMinor' => $order->total_minor,
                'createdAt' => $order->created_at->toIso8601String(),
                'createdBy' => $order->creator->name,
                'confirmedAt' => $order->confirmed_at?->toIso8601String(),
                'lines' => $order->lines->map(fn (SalesOrderLine $line) => [
                    'id' => $line->id,
                    'item' => $line->item->name,
                    'unit' => $line->item->unit,
                    'pack' => $line->packLabel(),
                    'quantity' => $line->quantity,
                    'delivered' => $line->quantity_delivered,
                    'remaining' => $line->remaining(),
                    // Whole packs (or units) the branch could ship today.
                    'available' => intdiv($stock->sellable($line->item, $order->branch_id), $line->units_per_pack),
                    'unitPriceMinor' => $line->unit_price_minor,
                    'discountPercent' => $line->discount_percent,
                    'lineTotalMinor' => $line->line_total_minor,
                ]),
                'deliveries' => $order->deliveries->map(fn (DeliveryNote $delivery) => [
                    'id' => $delivery->id,
                    'number' => $delivery->number,
                    'deliveredAt' => $delivery->delivered_at->toIso8601String(),
                    'deliveredBy' => $delivery->deliverer->name,
                    'saleId' => $delivery->sale->id,
                    'invoiceNumber' => $delivery->sale->number,
                    'totalMinor' => $delivery->sale->total_minor,
                ]),
            ],
            'customer' => [
                'id' => $order->customer->id,
                'number' => $order->customer->number,
                'name' => $order->customer->name,
                'owedMinor' => $owed,
                'availableMinor' => max(0, $order->customer->credit_limit_minor - $owed),
            ],
            'canManage' => $this->allows('orders.manage'),
            'canDeliver' => $this->allows('orders.deliver') && $order->status->canDeliver(),
        ]);
    }

    public function confirm(Request $request, SalesOrder $order, SalesOrders $orders): RedirectResponse
    {
        $this->ensureHere($order);
        $orders->confirm($order, $this->user($request));

        return back()->with('success', "Quotation {$order->quote_number} confirmed as order {$order->order_number}.");
    }

    public function cancel(SalesOrder $order, SalesOrders $orders): RedirectResponse
    {
        $this->ensureHere($order);
        $orders->cancel($order);

        return back()->with('success', "{$order->reference()} cancelled.");
    }

    public function close(SalesOrder $order, SalesOrders $orders): RedirectResponse
    {
        $this->ensureHere($order);
        $orders->close($order);

        return back()->with('success', "{$order->reference()} closed: the rest will not be delivered.");
    }

    public function deliver(Request $request, SalesOrder $order, Deliver $deliver): RedirectResponse
    {
        $this->ensureHere($order);
        $validated = $request->validate([
            'lines' => ['required', 'array', 'max:200'],
            'lines.*' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'received_by_name' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:500'],
            'submission_id' => ['nullable', 'uuid'],
        ]);
        $quantities = array_map(fn ($quantity) => (int) $quantity, array_filter($validated['lines'], fn ($quantity) => $quantity !== null));

        try {
            $delivery = $deliver->handle(
                by: $this->user($request),
                order: $order,
                quantities: $quantities,
                receivedByName: $validated['received_by_name'] ?? null,
                note: $validated['note'] ?? null,
                submissionId: $validated['submission_id'] ?? null,
            );
        } catch (InsufficientStock $e) {
            $lineId = $order->lines()->where('item_id', $e->item->id)->whereIn('id', array_keys($quantities))->value('id');

            throw ValidationException::withMessages(["lines.{$lineId}" => $e->getMessage()]);
        }

        return to_route($this->industry->route('deliveries.show'), $delivery)->with('success', "Delivery {$delivery->number} recorded and invoiced.");
    }

    /**
     * The delivery note to print and hand over with the goods.
     */
    public function delivery(DeliveryNote $delivery): Response
    {
        abort_unless($delivery->branch_id === $this->branch->id() && $delivery->application === $this->industry->code(), 404);
        $delivery->load(['order.customer', 'sale:id,number,total_minor,due_on', 'deliverer:id,name', 'lines.orderLine.item']);

        return Inertia::render($this->industry->page('deliveries/show'), [
            'delivery' => [
                'id' => $delivery->id,
                'number' => $delivery->number,
                'deliveredAt' => $delivery->delivered_at->toIso8601String(),
                'deliveredBy' => $delivery->deliverer->name,
                'receivedByName' => $delivery->received_by_name,
                'note' => $delivery->note,
                'order' => [
                    'id' => $delivery->order->id,
                    'reference' => $delivery->order->reference(),
                    'customerReference' => $delivery->order->customer_reference,
                ],
                'customer' => [
                    'id' => $delivery->order->customer->id,
                    'name' => $delivery->order->customer->name,
                    'number' => $delivery->order->customer->number,
                    'address' => $delivery->order->customer->address,
                    'phone' => $delivery->order->customer->phone,
                ],
                'invoice' => [
                    'id' => $delivery->sale->id,
                    'number' => $delivery->sale->number,
                    'totalMinor' => $delivery->sale->total_minor,
                    'dueOn' => $delivery->sale->due_on?->toDateString(),
                ],
                'lines' => $delivery->lines->map(fn (DeliveryNoteLine $line) => [
                    'id' => $line->id,
                    'item' => $line->orderLine->item->name,
                    'unit' => $line->orderLine->item->unit,
                    'pack' => $line->orderLine->packLabel(),
                    'quantity' => $line->quantity,
                ]),
            ],
        ]);
    }

    /**
     * What can be sold on order, with its packs, the shop's own prices, and
     * what the branch has to ship.
     *
     * @return list<array<string, mixed>>
     */
    private function itemOptions(): array
    {
        $items = Item::query()
            ->where('active', true)
            ->where('restricted', false)
            ->ofKind($this->industry->itemKinds())
            ->with('packs')
            ->orderBy('name')
            ->get();
        $stock = app(Stock::class);

        return array_values($items->map(fn (Item $item) => [
            'id' => $item->id,
            'label' => $item->name,
            'unit' => $item->unit,
            'sellPriceMinor' => $item->sell_price_minor,
            'available' => $stock->sellable($item, $this->branch->id()),
            'packs' => array_values($item->packs->map(fn (ItemPack $pack) => [
                'id' => $pack->id,
                'label' => $pack->label(),
                'units' => $pack->units,
                'sellPriceMinor' => $pack->sellPrice($item),
            ])->all()),
        ])->all());
    }

    private function expired(SalesOrder $order): bool
    {
        return $order->status === SalesOrderStatus::Quote && $order->valid_until->lessThan(CarbonImmutable::today());
    }

    private function maxDiscountPercent(): int
    {
        return $this->allows('sales.discount') ? $this->industry->maxDiscountPercent() : 0;
    }

    /**
     * Orders of other branches, or of another industry module, look unknown.
     */
    private function ensureHere(SalesOrder $order): void
    {
        abort_unless($order->branch_id === $this->branch->id() && $order->application === $this->industry->code(), 404);
    }

    private function allows(string $name): bool
    {
        return app(CurrentAccess::class)->get()?->allowsIn($this->industry->permission($name), branchId: $this->branch->id()) ?? false;
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
