<?php

namespace App\Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\MasterData\Models\Supplier;
use App\Modules\Procurement\Actions\ReceiveGoods;
use App\Modules\Procurement\Models\GoodsReceipt;
use App\Modules\Procurement\Models\GoodsReceiptLine;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\PurchaseOrderLine;
use App\Modules\Procurement\Models\SupplierReturn;
use App\Support\CurrentBranch;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Tenancy\CurrentTenant;

/**
 * Goods received into the branch being worked in.
 */
class GoodsReceiptController extends Controller
{
    public function __construct(private readonly CurrentBranch $branch) {}

    public function index(): Response
    {
        return Inertia::render('receipts/index', [
            'receipts' => GoodsReceipt::query()
                ->where('branch_id', $this->branch->id())
                ->with(['supplier:id,name', 'purchaseOrder:id,number'])
                ->latest()
                ->limit(200)
                ->get()
                ->map(fn (GoodsReceipt $receipt) => [
                    'id' => $receipt->id,
                    'number' => $receipt->number,
                    'supplier' => $receipt->supplier->name,
                    'order' => $receipt->purchaseOrder?->number,
                    'supplierReference' => $receipt->supplier_reference,
                    'receivedOn' => $receipt->received_on->toDateString(),
                    'totalMinor' => $receipt->total_minor,
                ]),
        ]);
    }

    /**
     * Receive against an order (?order=<id>: lines prefilled with what is
     * still expected) or without one.
     */
    public function create(Request $request): Response
    {
        $order = null;
        if (is_string($request->query('order'))) {
            $order = PurchaseOrder::query()->with('lines.item')->find($request->query('order'));
            abort_if($order === null || $order->branch_id !== $this->branch->id() || ! $order->status->canReceive(), 404);
        }

        return Inertia::render('receipts/create', [
            'suppliers' => Supplier::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
            'medicines' => Item::query()->where('active', true)->orderBy('name')->get()
                ->map(fn (Item $item) => ['id' => $item->id, 'label' => $item->name, 'unit' => $item->unit]),
            'order' => $order === null ? null : [
                'id' => $order->id,
                'number' => $order->number,
                'supplierId' => $order->supplier_id,
                'lines' => $order->lines
                    ->filter(fn (PurchaseOrderLine $line) => $line->remaining() > 0)
                    ->values()
                    ->map(fn (PurchaseOrderLine $line) => [
                        'id' => $line->id,
                        'medicineId' => $line->item_id,
                        'medicine' => $line->item->name,
                        'remaining' => $line->remaining(),
                        'unitCost' => Money::toMajor($line->unit_cost_minor),
                    ]),
            ],
            'today' => CarbonImmutable::today()->toDateString(),
        ]);
    }

    public function store(Request $request, ReceiveGoods $receive): RedirectResponse
    {
        $organizationId = (string) app(CurrentTenant::class)->id();

        $validated = $request->validate([
            'supplier_id' => ['required', 'uuid', Rule::exists('suppliers', 'id')->where('organization_id', $organizationId)],
            'purchase_order_id' => ['nullable', 'uuid'],
            'supplier_reference' => ['nullable', 'string', 'max:128'],
            'received_on' => ['required', 'date', 'before_or_equal:today'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.item_id' => ['required', 'uuid', Rule::exists('items', 'id')->where('organization_id', $organizationId)],
            'lines.*.purchase_order_line_id' => ['nullable', 'uuid'],
            'lines.*.batch_number' => ['required', 'string', 'max:64'],
            'lines.*.expires_on' => ['required', 'date', 'after:received_on'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'lines.*.unit_cost' => ['required', 'string', Money::rule()],
        ]);

        $order = null;
        if (! empty($validated['purchase_order_id'])) {
            $order = PurchaseOrder::query()->find((string) $validated['purchase_order_id']);
            abort_if($order === null, 404);
        }

        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $receipt = $receive->handle(
            by: $user,
            branchId: $this->branch->id(),
            supplier: Supplier::query()->findOrFail((string) $validated['supplier_id']),
            order: $order,
            supplierReference: $validated['supplier_reference'] ?? null,
            receivedOn: CarbonImmutable::parse($validated['received_on']),
            lines: array_values(array_map(fn (array $line) => [
                'item_id' => $line['item_id'],
                'purchase_order_line_id' => $line['purchase_order_line_id'] ?? null,
                'batch_number' => trim($line['batch_number']),
                'expires_on' => $line['expires_on'],
                'quantity' => (int) $line['quantity'],
                'unit_cost_minor' => Money::toMinor($line['unit_cost']),
            ], $validated['lines'])),
        );

        return to_route('receipts.show', $receipt)->with('success', "{$receipt->number} received into stock.");
    }

    public function show(GoodsReceipt $receipt): Response
    {
        abort_unless($receipt->branch_id === $this->branch->id(), 404);
        $receipt->load(['supplier:id,name', 'purchaseOrder:id,number', 'receiver:id,name', 'lines.item']);
        $returned = SupplierReturnController::returned($receipt);

        return Inertia::render('receipts/show', [
            'returns' => SupplierReturn::query()
                ->where('goods_receipt_id', $receipt->id)
                ->orderBy('number')
                ->get(['id', 'number', 'returned_on', 'total_minor', 'reason'])
                ->map(fn (SupplierReturn $return) => [
                    'id' => $return->id,
                    'number' => $return->number,
                    'returnedOn' => $return->returned_on->toDateString(),
                    'totalMinor' => $return->total_minor,
                    'reason' => $return->reason,
                ]),
            'canReturn' => array_sum(array_map(fn (GoodsReceiptLine $line) => $line->quantity - ($returned[$line->id] ?? 0), $receipt->lines->all())) > 0,
            'receipt' => [
                'id' => $receipt->id,
                'number' => $receipt->number,
                'supplier' => $receipt->supplier->name,
                'order' => $receipt->purchaseOrder === null ? null : ['id' => $receipt->purchaseOrder->id, 'number' => $receipt->purchaseOrder->number],
                'supplierReference' => $receipt->supplier_reference,
                'receivedOn' => $receipt->received_on->toDateString(),
                'receivedBy' => $receipt->receiver->name,
                'totalMinor' => $receipt->total_minor,
                'lines' => $receipt->lines->map(fn (GoodsReceiptLine $line) => [
                    'id' => $line->id,
                    'medicine' => $line->item->name,
                    'batchNumber' => $line->batch_number,
                    'expiresOn' => $line->expires_on->toDateString(),
                    'quantity' => $line->quantity,
                    'returned' => $returned[$line->id] ?? 0,
                    'unitCostMinor' => $line->unit_cost_minor,
                    'lineTotalMinor' => $line->line_total_minor,
                ]),
            ],
        ]);
    }
}
