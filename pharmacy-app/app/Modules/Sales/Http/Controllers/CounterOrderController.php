<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Inventory\Actions\InsufficientStock;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Sales\Actions\CounterOrders;
use App\Modules\Sales\Actions\CounterPrinting;
use App\Modules\Sales\Actions\Tills;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Models\BranchCounterSettings;
use App\Modules\Sales\Models\CounterOrder;
use App\Modules\Sales\Models\CounterOrderLine;
use App\Modules\Sales\Models\PrintedDocument;
use App\Support\CurrentBranch;
use App\Support\Industry;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Access\CurrentAccess;

/**
 * Counter orders of the branch being worked in: the queue each counter
 * works from, taking an order, payment, hand-over and cancelling.
 */
class CounterOrderController extends Controller
{
    public function __construct(
        private readonly CurrentBranch $branch,
        private readonly Industry $industry,
        private readonly CounterOrders $orders,
        private readonly CounterPrinting $printing,
    ) {}

    /**
     * Orders waiting for payment or hand-over, and today's finished ones. A
     * scanned or typed order number opens that order.
     */
    public function index(Request $request): Response|RedirectResponse
    {
        $find = strtoupper(trim($request->string('find')->toString()));
        if ($find !== '') {
            $found = $this->here()->where('number', $find)->first();
            if ($found === null) {
                throw ValidationException::withMessages(['find' => "No order {$find} at this branch."]);
            }

            return to_route($this->industry->route('orders.show'), $found);
        }

        $orders = $this->here()
            ->where(fn ($query) => $query
                ->whereIn('status', [CounterOrder::OPEN, CounterOrder::PAID, CounterOrder::HANDED_OVER])
                ->orWhere('ordered_at', '>=', today()))
            ->with('orderer:id,name')
            ->oldest('ordered_at')
            ->limit(300)
            ->get();

        return Inertia::render($this->industry->page('orders/index'), [
            'orders' => $orders->map(fn (CounterOrder $order) => [
                'id' => $order->id,
                'number' => $order->number,
                'status' => $order->status,
                'customerName' => $order->customer_name,
                'totalMinor' => $order->total_minor,
                'orderedAt' => $order->ordered_at->toIso8601String(),
                'orderedBy' => $order->orderer->name,
                'awaits' => $order->awaitsPayment() ? 'payment' : ($order->awaitsHandOver() ? $order->workflow->handOver() : null),
            ]),
            'workflow' => $this->settings()->workflow->label(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.item_id' => ['required', 'uuid', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'items.*.discount_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'items.*.pack_id' => ['nullable', 'uuid'],
            'submission_id' => ['nullable', 'uuid'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:32'],
            'customer_id' => ['nullable', 'uuid'],
            // A direct counter handing the goods over unpaid.
            'pay_later' => ['boolean'],
        ]);
        $payLater = (bool) ($validated['pay_later'] ?? false);

        $order = $this->orders->create(
            by: $this->user($request),
            branchId: $this->branch->id(),
            items: array_values(array_map(fn (array $item) => [
                'item_id' => (string) $item['item_id'],
                'quantity' => (int) $item['quantity'],
                'discount_percent' => (int) ($item['discount_percent'] ?? 0),
                'pack_id' => isset($item['pack_id']) ? (string) $item['pack_id'] : null,
            ], $validated['items'])),
            customerName: $validated['customer_name'] ?? null,
            customerPhone: $validated['customer_phone'] ?? null,
            customerId: isset($validated['customer_id']) ? (string) $validated['customer_id'] : null,
            maxDiscountPercent: $this->maxDiscountPercent(),
            submissionId: $validated['submission_id'] ?? null,
            payLater: $payLater,
        );

        return to_route($this->industry->route('orders.show'), $order)
            ->with('success', $payLater ? "Order {$order->number} handed over: to pay at the cashier." : "Order {$order->number} taken.")
            ->with('print', $this->prints(CounterPrinting::ORDERED, $order));
    }

    public function show(Request $request, CounterOrder $order, Tills $tills): Response
    {
        $this->ensureHere($order);
        $order->load(['orderer:id,name', 'handler:id,name', 'canceller:id,name', 'sale:id,number,status', 'customer:id,number,name', 'lines.item', 'lines.pack']);
        $batches = Batch::query()
            ->findMany($order->lines->flatMap(fn (CounterOrderLine $line) => array_column($line->taken ?? [], 'batch_id'))->all())
            ->keyBy('id');
        $till = $tills->openFor($this->user($request), $this->branch->id());

        return Inertia::render($this->industry->page('orders/show'), [
            'order' => [
                'id' => $order->id,
                'number' => $order->number,
                'status' => $order->status,
                'workflow' => $order->workflow->label(),
                'handOver' => $order->workflow->handOver(),
                'paysFirst' => $order->workflow->paysFirst(),
                'customerName' => $order->customer_name,
                'customerPhone' => $order->customer_phone,
                'customer' => $order->customer === null ? null : ['id' => $order->customer->id, 'number' => $order->customer->number, 'name' => $order->customer->name],
                'totalMinor' => $order->total_minor,
                'orderedAt' => $order->ordered_at->toIso8601String(),
                'orderedBy' => $order->orderer->name,
                'paidAt' => $order->paid_at?->toIso8601String(),
                'sale' => $order->sale === null ? null : ['id' => $order->sale->id, 'number' => $order->sale->number, 'status' => $order->sale->status],
                'handedOverAt' => $order->handed_over_at?->toIso8601String(),
                'handedOverBy' => $order->handler?->name,
                'cancelledAt' => $order->cancelled_at?->toIso8601String(),
                'cancelledBy' => $order->canceller?->name,
                'cancelReason' => $order->cancel_reason,
                'lines' => $order->lines->map(fn (CounterOrderLine $line) => [
                    'id' => $line->id,
                    'item' => $line->item->name,
                    'pack' => $line->pack === null ? null : "{$line->pack->name} of {$line->pack->units}",
                    'quantity' => $line->quantity,
                    'discountPercent' => $line->discount_percent,
                    'lineTotalMinor' => $line->line_total_minor,
                    'batches' => array_map(fn (array $part) => [
                        'batchNumber' => $batches[$part['batch_id']]->batch_number ?? '',
                        'expiresOn' => ($batches[$part['batch_id']] ?? null)?->expires_on?->toDateString(),
                        'quantity' => $part['quantity'],
                    ], $line->taken ?? []),
                ]),
                'awaitsPayment' => $order->awaitsPayment(),
                'awaitsHandOver' => $order->awaitsHandOver(),
            ],
            'can' => [
                'pay' => $order->awaitsPayment() && $this->canHere('sales.create'),
                'handOver' => $order->awaitsHandOver() && $this->canHere('sales.handover'),
                'cancel' => ! $order->isPaid() && $order->status !== CounterOrder::CANCELLED
                    && ($this->canHere('sales.create') || $this->canHere('sales.handover')),
            ],
            'till' => $till === null ? null : ['id' => $till->id, 'number' => $till->number],
            'shifts' => [
                'required' => $this->settings()->shifts_required,
                'defaultFloat' => Money::toMajor($this->settings()->default_float_minor ?? 0),
            ],
            'paymentMethods' => array_map(fn (PaymentMethod $method) => ['value' => $method->value, 'label' => $method->label()], PaymentMethod::payments()),
            'prints' => [
                PrintedDocument::ORDER_SLIP => $this->printing->copies(PrintedDocument::ORDER_SLIP, $order->id),
                PrintedDocument::COLLECTION_SLIP => $this->printing->copies(PrintedDocument::COLLECTION_SLIP, $order->id),
                PrintedDocument::RECEIPT => $order->sale_id === null ? 0 : $this->printing->copies(PrintedDocument::RECEIPT, $order->sale_id),
            ],
        ]);
    }

    public function pay(Request $request, CounterOrder $order): RedirectResponse
    {
        $this->ensureHere($order);
        $validated = $request->validate([
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)->except(PaymentMethod::Account)],
            'payment_reference' => ['nullable', 'string', 'max:64'],
            'amount_tendered' => ['nullable', 'string', Money::rule()],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:32'],
        ]);

        try {
            $sale = $this->orders->pay(
                order: $order,
                by: $this->user($request),
                method: PaymentMethod::from($validated['payment_method']),
                paymentReference: $validated['payment_reference'] ?? null,
                tenderedMinor: isset($validated['amount_tendered']) ? Money::toMinor($validated['amount_tendered']) : null,
                customerName: $validated['customer_name'] ?? null,
                customerPhone: $validated['customer_phone'] ?? null,
            );
        } catch (InsufficientStock $e) {
            throw ValidationException::withMessages(['payment_method' => $e->getMessage()]);
        }

        $order->refresh();

        return to_route($this->industry->route('orders.show'), $order)
            ->with('success', "Order {$order->number} paid: sale {$sale->number}.")
            ->with('print', $this->prints(CounterPrinting::PAID, $order));
    }

    public function handOver(Request $request, CounterOrder $order): RedirectResponse
    {
        $this->ensureHere($order);

        try {
            $order = $this->orders->handOver($order, $this->user($request));
        } catch (InsufficientStock $e) {
            throw ValidationException::withMessages(['order' => $e->getMessage()]);
        }

        return to_route($this->industry->route('orders.show'), $order)
            ->with('success', "Order {$order->number} ".($order->workflow->handOver() === 'collection' ? 'collected' : 'dispensed').'.')
            ->with('print', $this->prints(CounterPrinting::HANDED_OVER, $order));
    }

    public function cancel(Request $request, CounterOrder $order): RedirectResponse
    {
        $this->ensureHere($order);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $order = $this->orders->cancel($order, $this->user($request), $validated['reason']);

        return back()->with('success', "Order {$order->number} cancelled.");
    }

    /**
     * Where the documents the branch prints after a step are printed from.
     *
     * @return list<string>
     */
    private function prints(string $step, CounterOrder $order): array
    {
        return array_map(fn (array $print) => $print['document'] === PrintedDocument::RECEIPT
            ? route($this->industry->route('sales.receipt'), ['sale' => $print['reference']->getKey(), 'auto' => 1])
            : route($this->industry->route('orders.print'), ['order' => $order->id, 'document' => $print['document'], 'auto' => 1]),
            $this->printing->after($step, $this->settings(), $order->loadMissing('sale')));
    }

    private function settings(): BranchCounterSettings
    {
        return BranchCounterSettings::forBranch($this->branch->id(), $this->industry->code());
    }

    /**
     * @return Builder<CounterOrder>
     */
    private function here(): Builder
    {
        return CounterOrder::query()
            ->where('branch_id', $this->branch->id())
            ->where('application', $this->industry->code());
    }

    /**
     * Orders of other branches, and of another industry's counter, look unknown.
     */
    private function ensureHere(CounterOrder $order): void
    {
        abort_unless($order->branch_id === $this->branch->id() && $order->application === $this->industry->code(), 404);
    }

    private function maxDiscountPercent(): int
    {
        return $this->canHere('sales.discount') ? $this->industry->maxDiscountPercent() : 0;
    }

    private function canHere(string $name): bool
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
