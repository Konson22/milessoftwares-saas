<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\WorksInBranch;
use App\Models\FuelProduct;
use App\Models\FuelReceipt;
use App\Models\PurchaseLine;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Tank;
use App\Petrol\Currencies;
use App\Petrol\Permissions;
use App\Petrol\Purchasing;
use App\Support\Money;
use App\Support\Volume;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseController extends Controller
{
    use WorksInBranch;

    public function index(): Response
    {
        return Inertia::render('purchases/index', [
            'orders' => PurchaseOrder::query()->with('supplier')->where('branch_id', $this->branchId())->latest()->limit(40)->get()->map(fn (PurchaseOrder $order) => [
                'id' => $order->id,
                'number' => $order->number,
                'supplier' => $order->supplier->name,
                'status' => $order->status,
                'orderedOn' => $order->ordered_at->toDateString(),
            ]),
            'suppliers' => Supplier::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
            'products' => FuelProduct::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
            'tanks' => Tank::query()->where('branch_id', $this->branchId())->where('status', Tank::ACTIVE)->orderBy('code')->get(['id', 'code', 'fuel_product_id']),
            'canManage' => $this->canHere(Permissions::PURCHASES_MANAGE),
        ]);
    }

    public function show(PurchaseOrder $order): Response
    {
        $this->ensureHere($order->branch_id);
        $order->load(['supplier', 'lines.product', 'receipts']);

        return Inertia::render('purchases/show', [
            'order' => [
                'id' => $order->id,
                'number' => $order->number,
                'supplier' => $order->supplier->name,
                'status' => $order->status,
                'orderedOn' => $order->ordered_at->toDateString(),
                'lines' => $order->lines->map(fn (PurchaseLine $line) => [
                    'id' => $line->id,
                    'product' => $line->product->name,
                    'productId' => $line->fuel_product_id,
                    'ordered' => Volume::toLitres($line->quantity_milli),
                    'received' => Volume::toLitres($line->received_milli),
                    'remaining' => Volume::toLitres($line->remaining()),
                    'unitCostMinor' => $line->unit_cost_minor,
                ]),
            ],
            'receipts' => $order->receipts->map(fn (FuelReceipt $receipt) => [
                'id' => $receipt->id,
                'number' => $receipt->number,
                'measured' => Volume::toLitres($receipt->measured_milli),
                'declared' => Volume::toLitres($receipt->declared_milli),
            ]),
            'tanks' => Tank::query()->where('branch_id', $order->branch_id)->where('status', Tank::ACTIVE)->orderBy('code')->get(['id', 'code', 'fuel_product_id']),
            'canManage' => $this->canHere(Permissions::PURCHASES_MANAGE),
        ]);
    }

    public function store(Request $request, Purchasing $purchasing): RedirectResponse
    {
        $validated = $request->validate([
            'supplier_id' => ['required', 'uuid'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.fuel_product_id' => ['required', 'uuid'],
            'lines.*.litres' => ['required', Volume::rule()],
            'lines.*.unit_cost' => ['required', Money::anyRule()],
            'lines.*.currency' => ['nullable', Rule::in(app(Currencies::class)->acceptedCodes($this->branchId()))],
        ]);
        $supplier = Supplier::query()->whereKey($validated['supplier_id'])->where('active', true)->firstOrFail();
        $order = $purchasing->order($this->branchId(), $supplier, $validated['lines'], null, null, $this->member($request));

        return to_route('purchases.show', $order)->with('success', "Order {$order->number} placed.");
    }

    public function receive(Request $request, PurchaseOrder $order, Purchasing $purchasing): RedirectResponse
    {
        $this->ensureHere($order->branch_id);
        $validated = $this->receiptInput($request);
        $line = PurchaseLine::query()->whereKey($validated['purchase_line_id'] ?? null)->where('purchase_order_id', $order->id)->firstOrFail();
        $tank = Tank::query()->whereKey($validated['tank_id'])->firstOrFail();
        $this->ensureHere($tank->branch_id);

        $receipt = $purchasing->receive($order->branch_id, $order->supplier, $this->receipt($validated, $line->id), $this->member($request));

        return back()->with('success', "Receipt {$receipt->number} recorded.");
    }

    public function receiveDirect(Request $request, Purchasing $purchasing): RedirectResponse
    {
        $validated = $this->receiptInput($request);
        $supplier = Supplier::query()->whereKey($validated['supplier_id'] ?? null)->where('active', true)->firstOrFail();
        $tank = Tank::query()->whereKey($validated['tank_id'])->firstOrFail();
        $this->ensureHere($tank->branch_id);
        $receipt = $purchasing->receive($tank->branch_id, $supplier, $this->receipt($validated, null), $this->member($request));

        return back()->with('success', "Receipt {$receipt->number} recorded.");
    }

    /**
     * @return array<string, mixed>
     */
    private function receiptInput(Request $request): array
    {
        return $request->validate([
            'supplier_id' => ['nullable', 'uuid'],
            'purchase_line_id' => ['nullable', 'uuid'],
            'tank_id' => ['required', 'uuid'],
            'declared' => ['required', Volume::rule()],
            'measured' => ['required', Volume::rule()],
            'unit_cost' => ['required', Money::anyRule()],
            'currency' => ['nullable', Rule::in(app(Currencies::class)->acceptedCodes($this->branchId()))],
            'tanker' => ['nullable', 'string', 'max:64'],
            'driver' => ['nullable', 'string', 'max:128'],
            'supplier_reference' => ['nullable', 'string', 'max:128'],
            'idempotency_key' => ['nullable', 'uuid'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{tank_id: string, declared: string, measured: string, unit_cost: string, currency: ?string, tanker: ?string, driver: ?string, supplier_reference: ?string, idempotency_key: ?string, purchase_line_id: ?string}
     */
    private function receipt(array $validated, ?string $lineId): array
    {
        return [
            'tank_id' => (string) $validated['tank_id'],
            'declared' => (string) $validated['declared'],
            'measured' => (string) $validated['measured'],
            'unit_cost' => (string) $validated['unit_cost'],
            'currency' => isset($validated['currency']) ? (string) $validated['currency'] : null,
            'tanker' => $this->text($validated['tanker'] ?? null),
            'driver' => $this->text($validated['driver'] ?? null),
            'supplier_reference' => $this->text($validated['supplier_reference'] ?? null),
            'idempotency_key' => $this->text($validated['idempotency_key'] ?? null),
            'purchase_line_id' => $lineId,
        ];
    }
}
