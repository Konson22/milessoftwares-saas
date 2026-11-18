<?php

namespace App\Support;

use App\Actions\Stock;
use App\Models\MenuItem;
use App\Models\StockMovement;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Every drink's stock movements in one or more branches, newest first: for
 * a branch's stock movements page and management's across branches. The
 * same filters and the same rows in both.
 */
final class MovementLedger
{
    /** The longest period one page lists. */
    public const MAX_DAYS = 92;

    public const TYPES = [
        StockMovement::OPENING => 'Added',
        StockMovement::DELIVERY => 'Delivered',
        StockMovement::SALE => 'Billed',
        StockMovement::BILL_VOID => 'Bill voided',
        StockMovement::COUNT => 'Counted',
        StockMovement::WRITE_OFF => 'Written off',
    ];

    /**
     * The filters asked for, checked: a period (the last seven days without
     * one), a type and a drink.
     *
     * @return array{from: CarbonImmutable, to: CarbonImmutable, type: string|null, item: string|null}
     */
    public function filters(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'type' => ['nullable', Rule::in(array_keys(self::TYPES))],
            'item' => ['nullable', 'uuid'],
        ]);

        $to = isset($validated['to']) ? CarbonImmutable::parse($validated['to'])->endOfDay() : CarbonImmutable::today()->endOfDay();
        $from = isset($validated['from']) ? CarbonImmutable::parse($validated['from'])->startOfDay() : $to->subDays(6)->startOfDay();
        if ($from->diffInDays($to) >= self::MAX_DAYS) {
            throw ValidationException::withMessages(['from' => 'Choose at most '.self::MAX_DAYS.' days.']);
        }

        return ['from' => $from, 'to' => $to, 'type' => $validated['type'] ?? null, 'item' => $validated['item'] ?? null];
    }

    /**
     * A page of movements, with what came in and went out over the whole
     * period (not just the page).
     *
     * @param  list<string>  $branchIds
     * @param  array{from: CarbonImmutable, to: CarbonImmutable, type: string|null, item: string|null}  $filters
     * @param  array<string, string>  $branchNames
     * @return array<string, mixed>
     */
    public function page(array $branchIds, array $filters, array $branchNames = []): array
    {
        $query = StockMovement::query()
            ->whereIn('branch_id', $branchIds)
            ->whereBetween('created_at', [$filters['from'], $filters['to']])
            ->when($filters['type'] !== null, fn ($query) => $query->where('type', $filters['type']))
            ->when($filters['item'] !== null, fn ($query) => $query->where('menu_item_id', $filters['item']));

        $totals = (clone $query)->toBase()
            ->selectRaw('coalesce(sum(case when quantity > 0 then quantity else 0 end), 0) as gained, coalesce(sum(case when quantity < 0 then -quantity else 0 end), 0) as lost, coalesce(sum(case when quantity < 0 then -quantity * unit_cost_minor else 0 end), 0) as lost_cost')
            ->first();

        $page = $query
            ->with(['item:id,name,unit', 'creator:id,name'])
            ->latest('created_at')
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString();

        return [
            'in' => (int) ($totals->gained ?? 0),
            'out' => (int) ($totals->lost ?? 0),
            'outCostMinor' => (int) ($totals->lost_cost ?? 0),
            'data' => collect($page->items())->map(fn (StockMovement $movement) => [
                'id' => $movement->id,
                'at' => $movement->created_at->toIso8601String(),
                'branchId' => $movement->branch_id,
                'branch' => $branchNames[$movement->branch_id] ?? null,
                'itemId' => $movement->menu_item_id,
                'item' => $movement->item->name,
                'unit' => $movement->item->unit,
                'type' => $movement->type,
                'quantity' => $movement->quantity,
                'unitCostMinor' => $movement->unit_cost_minor,
                'reason' => $movement->reason === null ? null : (Stock::REASONS[$movement->reason] ?? $movement->reason),
                'note' => $movement->note,
                'billId' => $movement->bill_id,
                'deliveryId' => $movement->delivery_id,
                'by' => $movement->creator->name,
            ])->all(),
            'currentPage' => $page->currentPage(),
            'lastPage' => $page->lastPage(),
            'total' => $page->total(),
            'prev' => $page->previousPageUrl(),
            'next' => $page->nextPageUrl(),
        ];
    }

    /**
     * The filter choices: types and the drinks that keep stock.
     *
     * @return array{types: list<array{value: string, label: string}>, items: list<array{value: string, label: string}>}
     */
    public function options(): array
    {
        return [
            'types' => array_map(fn (string $value, string $label) => ['value' => $value, 'label' => $label], array_keys(self::TYPES), self::TYPES),
            'items' => array_values(MenuItem::query()->where('kind', MenuItem::DRINK)->orderBy('name')->get(['id', 'name'])
                ->map(fn (MenuItem $item) => ['value' => $item->id, 'label' => $item->name])->all()),
        ];
    }

    /**
     * @param  array{from: CarbonImmutable, to: CarbonImmutable, type: string|null, item: string|null}  $filters
     * @return array{from: string, to: string, type: string|null, item: string|null}
     */
    public function shown(array $filters): array
    {
        return [
            'from' => $filters['from']->toDateString(),
            'to' => $filters['to']->toDateString(),
            'type' => $filters['type'],
            'item' => $filters['item'],
        ];
    }
}
