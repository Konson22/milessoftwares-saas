<?php

namespace App\Http\Controllers;

use App\Actions\Stock;
use App\Http\Controllers\Concerns\WorksInBranch;
use App\Models\MenuItem;
use App\Models\StockDelivery;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Drinks delivered by suppliers to the branch being worked in.
 */
class DeliveryController extends Controller
{
    use WorksInBranch;

    public function index(): Response
    {
        $deliveries = StockDelivery::query()
            ->where('branch_id', $this->branchId())
            ->with('receiver:id,name')
            ->withCount('movements')
            ->latest('received_on')
            ->latest('created_at')
            ->limit(100)
            ->get();

        return Inertia::render('stock/deliveries/index', [
            'deliveries' => $deliveries->map(fn (StockDelivery $delivery) => [
                'id' => $delivery->id,
                'number' => $delivery->number,
                'supplierName' => $delivery->supplier_name,
                'supplierReference' => $delivery->supplier_reference,
                'receivedOn' => $delivery->received_on->toDateString(),
                'receivedBy' => $delivery->receiver->name,
                'drinks' => $delivery->movements_count,
                'totalMinor' => $delivery->total_minor,
            ]),
            'canReceive' => $this->canHere('restaurant.stock.receive'),
        ]);
    }

    public function create(): Response
    {
        $levels = StockLevel::query()->where('branch_id', $this->branchId())->get()->keyBy('menu_item_id');

        return Inertia::render('stock/deliveries/create', [
            'drinks' => MenuItem::query()
                ->where('kind', MenuItem::DRINK)
                ->where('active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'unit'])
                ->map(fn (MenuItem $item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'unit' => $item->unit,
                    'onHand' => $levels->get($item->id)->quantity ?? 0,
                    // What one has cost so far: filled in as the likely price.
                    'averageCostMinor' => $levels->get($item->id)->average_cost_minor ?? 0,
                ])
                ->values(),
            // Suppliers delivered from before, newest first.
            'suppliers' => StockDelivery::query()
                ->where('branch_id', $this->branchId())
                ->latest('created_at')
                ->limit(200)
                ->pluck('supplier_name')
                ->unique()
                ->take(30)
                ->values(),
        ]);
    }

    public function store(Request $request, Stock $stock): RedirectResponse
    {
        $validated = $request->validate([
            'supplier_name' => ['required', 'string', 'max:255'],
            'supplier_reference' => ['nullable', 'string', 'max:128'],
            'note' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.item_id' => ['required', 'uuid', 'distinct'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'lines.*.unit_cost' => ['required', 'string', Money::rule()],
        ]);

        $items = MenuItem::query()->whereKey(array_column($validated['lines'], 'item_id'))->where('active', true)->get()->keyBy('id');
        $lines = array_values(array_map(function (array $line) use ($items) {
            $item = $items->get($line['item_id']) ?? throw ValidationException::withMessages(['lines' => 'A drink chosen is no longer on the menu.']);

            return ['item' => $item, 'quantity' => (int) $line['quantity'], 'unit_cost_minor' => Money::toMinor($line['unit_cost'])];
        }, $validated['lines']));

        $delivery = $stock->deliver(
            $this->branchId(),
            $validated['supplier_name'],
            $validated['supplier_reference'] ?? null,
            $lines,
            $this->member($request),
            $validated['note'] ?? null,
        );

        return to_route('stock.deliveries.show', $delivery)->with('success', "Delivery {$delivery->number} received: ".Money::format($delivery->total_minor).'.');
    }

    public function show(StockDelivery $delivery): Response
    {
        $this->ensureHere($delivery->branch_id);
        $delivery->load(['receiver:id,name', 'movements.item:id,name,unit']);

        return Inertia::render('stock/deliveries/show', [
            'delivery' => [
                'id' => $delivery->id,
                'number' => $delivery->number,
                'supplierName' => $delivery->supplier_name,
                'supplierReference' => $delivery->supplier_reference,
                'receivedOn' => $delivery->received_on->toDateString(),
                'receivedBy' => $delivery->receiver->name,
                'note' => $delivery->note,
                'totalMinor' => $delivery->total_minor,
                'lines' => $delivery->movements->map(fn (StockMovement $movement) => [
                    'id' => $movement->id,
                    'itemId' => $movement->menu_item_id,
                    'name' => $movement->item->name,
                    'unit' => $movement->item->unit,
                    'quantity' => $movement->quantity,
                    'unitCostMinor' => $movement->unit_cost_minor,
                    'totalMinor' => $movement->quantity * $movement->unit_cost_minor,
                ]),
            ],
        ]);
    }
}
