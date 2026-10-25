<?php

namespace App\Http\Controllers;

use App\Actions\Stock;
use App\Http\Controllers\Concerns\WorksInBranch;
use App\Models\MenuItem;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Drinks in stock at the branch being worked in (dishes keep none).
 */
class StockController extends Controller
{
    use WorksInBranch;

    public function index(Request $request): Response
    {
        $search = $request->string('search')->toString();
        $filter = $request->string('filter')->toString() === 'low' ? 'low' : 'all';

        $levels = StockLevel::query()->where('branch_id', $this->branchId())->get()->keyBy('menu_item_id');
        $drinks = MenuItem::query()
            ->where('kind', MenuItem::DRINK)
            ->where('active', true)
            ->search($search)
            ->orderBy('name')
            ->limit(300)
            ->get();

        $rows = $drinks->map(function (MenuItem $item) use ($levels) {
            $onHand = $levels->get($item->id)->quantity ?? 0;

            return [
                'id' => $item->id,
                'name' => $item->name,
                'unit' => $item->unit,
                'onHand' => $onHand,
                'averageCostMinor' => $levels->get($item->id)->average_cost_minor ?? 0,
                'reorderLevel' => $item->reorder_level,
                'low' => $onHand <= $item->reorder_level,
            ];
        });

        return Inertia::render('stock/index', [
            'rows' => $rows->when($filter === 'low', fn ($rows) => $rows->where('low', true))->values(),
            'filters' => ['search' => $search, 'filter' => $filter],
        ]);
    }

    public function show(Request $request, MenuItem $item): Response
    {
        abort_unless($item->tracksStock(), 404);
        $level = StockLevel::query()->where('branch_id', $this->branchId())->where('menu_item_id', $item->id)->first();

        return Inertia::render('stock/show', [
            'item' => [
                'id' => $item->id,
                'name' => $item->name,
                'unit' => $item->unit,
                'reorderLevel' => $item->reorder_level,
                'onHand' => $level->quantity ?? 0,
                'averageCostMinor' => $level->average_cost_minor ?? 0,
            ],
            'movements' => StockMovement::query()
                ->where('branch_id', $this->branchId())
                ->where('menu_item_id', $item->id)
                ->with('creator:id,name')
                ->latest('created_at')
                ->limit(50)
                ->get()
                ->map(fn (StockMovement $movement) => [
                    'id' => $movement->id,
                    'at' => $movement->created_at->toIso8601String(),
                    'type' => $movement->type,
                    'quantity' => $movement->quantity,
                    'reason' => $movement->reason === null ? null : (Stock::REASONS[$movement->reason] ?? $movement->reason),
                    'note' => $movement->note,
                    'by' => $movement->creator->name,
                ]),
            'reasons' => array_map(fn (string $value, string $label) => ['value' => $value, 'label' => $label], array_keys(Stock::REASONS), Stock::REASONS),
            'canAdjust' => $this->canHere('restaurant.stock.adjust'),
        ]);
    }

    public function opening(Request $request, MenuItem $item, Stock $stock): RedirectResponse
    {
        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'unit_cost' => ['required', 'string', Money::rule()],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $stock->opening($item, $this->branchId(), (int) $validated['quantity'], Money::toMinor($validated['unit_cost']), $this->member($request), $validated['note'] ?? null);

        return back()->with('success', "{$validated['quantity']} {$item->unit} of {$item->name} added.");
    }

    public function adjust(Request $request, MenuItem $item, Stock $stock): RedirectResponse
    {
        $validated = $request->validate([
            'change' => ['required', 'integer', 'min:-1000000', 'max:1000000'],
            'reason' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $stock->adjust($item, $this->branchId(), (int) $validated['change'], $validated['reason'], $this->member($request), $validated['note'] ?? null);

        return back()->with('success', "Stock of {$item->name} corrected.");
    }
}
