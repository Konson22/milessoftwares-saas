<?php

namespace App\Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemPack;
use App\Modules\MasterData\Models\Supplier;
use App\Modules\Procurement\Actions\Purchasing;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\PurchaseOrderLine;
use App\Support\CurrentBranch;
use App\Support\Industry;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Access\CurrentAccess;
use Miles\PlatformClient\Tenancy\CurrentTenant;

/**
 * Purchase orders of the branch being worked in.
 */
class PurchaseOrderController extends Controller
{
    public function __construct(
        private readonly CurrentBranch $branch,
        private readonly Industry $industry,
    ) {}

    public function index(): Response
    {
        return Inertia::render($this->industry->page('purchase-orders/index'), [
            'orders' => PurchaseOrder::query()
                ->where('branch_id', $this->branch->id())
                ->where('application', $this->industry->code())
                ->with(['supplier:id,name', 'lines'])
                ->latest()
                ->limit(200)
                ->get()
                ->map(fn (PurchaseOrder $order) => [
                    'id' => $order->id,
                    'number' => $order->number,
                    'supplier' => $order->supplier->name,
                    'status' => $order->status->value,
                    'expectedOn' => $order->expected_on?->toDateString(),
                    'totalMinor' => $order->totalMinor(),
                    'createdAt' => $order->created_at->toIso8601String(),
                ]),
            'canManage' => $this->allows($this->industry->permission('purchasing.manage')),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render($this->industry->page('purchase-orders/create'), [
            'suppliers' => Supplier::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
            'items' => self::itemOptions(),
        ]);
    }

    public function store(Request $request, Purchasing $purchasing): RedirectResponse
    {
        $validated = $request->validate([
            'supplier_id' => ['required', 'uuid', Rule::exists('suppliers', 'id')->where('organization_id', $this->organizationId())->where('active', true)],
            'expected_on' => ['nullable', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.item_id' => ['required', 'uuid', 'distinct', Rule::exists('items', 'id')->where('organization_id', $this->organizationId())],
            'lines.*.pack_id' => ['nullable', 'uuid'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'lines.*.unit_cost' => ['required', 'string', Money::rule()],
        ]);

        $order = $purchasing->createOrder(
            by: $this->user($request),
            branchId: $this->branch->id(),
            supplier: Supplier::query()->findOrFail((string) $validated['supplier_id']),
            lines: array_values(array_map(fn (array $line) => [
                'item_id' => $line['item_id'],
                'pack_id' => $line['pack_id'] ?? null,
                'quantity' => (int) $line['quantity'],
                'unit_cost_minor' => Money::toMinor($line['unit_cost']),
            ], $validated['lines'])),
            expectedOn: isset($validated['expected_on']) ? CarbonImmutable::parse($validated['expected_on']) : null,
            notes: $validated['notes'] ?? null,
        );

        return to_route($this->industry->route('purchase-orders.show'), $order)->with('success', "Order {$order->number} drafted.");
    }

    public function show(PurchaseOrder $order): Response
    {
        $this->ensureHere($order);
        $order->load(['supplier:id,name', 'lines.item']);

        return Inertia::render($this->industry->page('purchase-orders/show'), [
            'order' => [
                'id' => $order->id,
                'number' => $order->number,
                'supplier' => $order->supplier->name,
                'status' => $order->status->value,
                'expectedOn' => $order->expected_on?->toDateString(),
                'notes' => $order->notes,
                'totalMinor' => $order->totalMinor(),
                'lines' => $order->lines->map(fn (PurchaseOrderLine $line) => [
                    'id' => $line->id,
                    'item' => $line->item->name,
                    'unit' => $line->item->unit,
                    'pack' => $line->packLabel(),
                    'ordered' => $line->quantity_ordered,
                    'received' => $line->quantity_received,
                    'unitCostMinor' => $line->unit_cost_minor,
                ]),
            ],
            'canManage' => $this->allows($this->industry->permission('purchasing.manage')),
            'canReceive' => $this->allows($this->industry->permission('purchasing.receive')) && $order->status->canReceive(),
        ]);
    }

    public function markOrdered(PurchaseOrder $order, Purchasing $purchasing): RedirectResponse
    {
        $this->ensureHere($order);
        $purchasing->markOrdered($order);

        return back()->with('success', "Order {$order->number} marked as sent to the supplier.");
    }

    public function cancel(PurchaseOrder $order, Purchasing $purchasing): RedirectResponse
    {
        $this->ensureHere($order);
        $purchasing->cancel($order);

        return back()->with('success', "Order {$order->number} cancelled.");
    }

    /**
     * What can be bought, with the packs it comes in (orders and deliveries),
     * its selling prices and what a unit last cost, so a line can show what
     * it costs per unit against what it sells for.
     *
     * @return list<array{id: string, label: string, unit: string, tracksExpiry: bool, sellPriceMinor: int, lastUnitCostMinor: int|null, packs: list<array{id: string, label: string, name: string, units: int, sellPriceMinor: int}>}>
     */
    public static function itemOptions(): array
    {
        $items = Item::query()->where('active', true)->ofKind(app(Industry::class)->itemKinds())->with('packs')->orderBy('name')->get();
        $lastCosts = Batch::lastUnitCosts($items->modelKeys());

        return array_values($items
            ->map(fn (Item $item) => [
                'id' => $item->id,
                'label' => $item->name,
                'unit' => $item->unit,
                'tracksExpiry' => $item->tracks_expiry,
                'sellPriceMinor' => $item->sell_price_minor,
                'lastUnitCostMinor' => $lastCosts[$item->id] ?? null,
                'packs' => array_values($item->packs->map(fn (ItemPack $pack) => [
                    'id' => $pack->id,
                    'label' => $pack->label(),
                    'name' => $pack->name,
                    'units' => $pack->units,
                    'sellPriceMinor' => $pack->sellPrice($item),
                ])->all()),
            ])
            ->all());
    }

    /**
     * Orders of other branches, or of another industry module, look unknown.
     */
    private function ensureHere(PurchaseOrder $order): void
    {
        abort_unless($order->branch_id === $this->branch->id() && $order->application === $this->industry->code(), 404);
    }

    private function allows(string $permission): bool
    {
        return app(CurrentAccess::class)->get()?->allowsIn($permission, branchId: $this->branch->id()) ?? false;
    }

    private function organizationId(): string
    {
        return (string) app(CurrentTenant::class)->id();
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
