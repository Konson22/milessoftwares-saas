<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Item;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleLine;
use App\Support\CsvExport;
use App\Support\CurrentBranch;
use App\Support\Industry;
use App\Support\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What to do about the shelves, measured against the period's sales: what
 * to reorder (sellable stock at or under its reorder level, as on the
 * stock page) and what is not moving (stock that sold nothing, or would
 * take longer than SLOW_COVER_DAYS to sell at the period's pace), with
 * the money tied up in it at cost.
 */
class StockReportController extends Controller
{
    /** Stock that would last longer than this at the period's pace is slow. */
    public const SLOW_COVER_DAYS = 90;

    public function __invoke(Request $request, CurrentBranch $branch, Industry $industry): Response|StreamedResponse
    {
        $period = ReportPeriod::fromRequest($request);
        $today = CarbonImmutable::today();
        $days = (int) $period->from->diffInDays($period->to) + 1;

        $items = Item::query()
            ->where('active', true)
            ->ofKind($industry->itemKinds())
            ->orderBy('name')
            ->get(['id', 'name', 'category', 'unit', 'reorder_level']);

        // Sellable now: available, unexpired, on this branch's shelves.
        $onHand = Batch::query()
            ->where('branch_id', $branch->id())
            ->where('status', Batch::AVAILABLE)
            ->where('quantity_on_hand', '>', 0)
            ->unexpired($today)
            ->groupBy('item_id')
            ->selectRaw('item_id, SUM(quantity_on_hand) AS units, SUM(quantity_on_hand * unit_cost_minor) AS cost')
            ->toBase()
            ->get()
            ->keyBy('item_id');

        // What the latest batch at this branch cost per unit.
        $lastCost = Batch::query()
            ->where('branch_id', $branch->id())
            ->latest()
            ->toBase()
            ->get(['item_id', 'unit_cost_minor'])
            ->unique('item_id')
            ->pluck('unit_cost_minor', 'item_id');

        $sales = fn () => Sale::query()
            ->where('branch_id', $branch->id())
            ->where('application', $industry->code())
            ->where('status', Sale::COMPLETED);
        $sold = SaleLine::query()
            ->whereIn('sale_id', $sales()->whereBetween('sold_at', $period->between())->select('id'))
            ->groupBy('item_id')
            ->selectRaw('item_id, SUM(quantity) AS units')
            ->toBase()
            ->pluck('units', 'item_id');
        $lastSold = SaleLine::query()
            ->join('sales', 'sales.id', '=', 'sale_lines.sale_id')
            ->whereIn('sale_lines.sale_id', $sales()->select('id'))
            ->groupBy('sale_lines.item_id')
            ->selectRaw('sale_lines.item_id, MAX(sales.sold_at) AS last')
            ->toBase()
            ->pluck('last', 'item_id');

        $rows = $items->map(function (Item $item) use ($onHand, $lastCost, $sold, $lastSold, $days) {
            $units = (int) ($onHand->get($item->id)->units ?? 0);
            $soldUnits = (int) ($sold[$item->id] ?? 0);
            $last = $lastSold[$item->id] ?? null;

            return [
                'id' => $item->id,
                'name' => $item->name,
                'category' => $item->category,
                'unit' => $item->unit,
                'onHand' => $units,
                'stockCostMinor' => (int) ($onHand->get($item->id)->cost ?? 0),
                'reorderLevel' => $item->reorder_level,
                'lastCostMinor' => isset($lastCost[$item->id]) ? (int) $lastCost[$item->id] : null,
                'soldUnits' => $soldUnits,
                'perDay' => round($soldUnits / $days, 2),
                // How many days the shelf lasts at the period's pace; none when nothing sold.
                'coverDays' => $soldUnits > 0 ? (int) floor($units * $days / $soldUnits) : null,
                'lastSoldOn' => $last === null ? null : CarbonImmutable::parse((string) $last)->toDateString(),
            ];
        });

        $reorder = $rows
            ->filter(fn (array $row) => $row['onHand'] <= $row['reorderLevel'])
            ->map(fn (array $row) => [...$row, 'shortBy' => $row['reorderLevel'] - $row['onHand']])
            ->sortBy([['shortBy', 'desc'], ['name', 'asc']])
            ->values();
        $slow = $rows
            ->filter(fn (array $row) => $row['onHand'] > 0 && ($row['coverDays'] === null || $row['coverDays'] > self::SLOW_COVER_DAYS))
            ->sortByDesc('stockCostMinor')
            ->values();

        $props = [
            ...$period->toArray(),
            'slowCoverDays' => self::SLOW_COVER_DAYS,
            'reorder' => $reorder,
            'slow' => $slow,
            'totals' => [
                'reorderCount' => $reorder->count(),
                'outOfStock' => $reorder->where('onHand', 0)->count(),
                'slowCount' => $slow->count(),
                'slowCostMinor' => (int) $slow->sum('stockCostMinor'),
            ],
        ];
        $name = "{$period->from->toDateString()}-to-{$period->to->toDateString()}";

        return match ($request->query('export')) {
            null => Inertia::render($industry->page('reports/stock'), $props),
            'reorder' => CsvExport::download("reorder-{$today->toDateString()}", ['Item' => 'name', 'Category' => 'category', 'Unit' => 'unit', 'On hand' => 'onHand', 'Reorder level' => 'reorderLevel', 'Short by' => 'shortBy', 'Sold in period' => 'soldUnits', 'Per day' => 'perDay', 'Last cost' => 'lastCostMinor'], $reorder),
            'slow' => CsvExport::download("slow-movers-{$name}", ['Item' => 'name', 'Category' => 'category', 'Unit' => 'unit', 'On hand' => 'onHand', 'Stock at cost' => 'stockCostMinor', 'Sold in period' => 'soldUnits', 'Days of cover' => 'coverDays', 'Last sold' => 'lastSoldOn'], $slow),
            default => abort(404),
        };
    }
}
