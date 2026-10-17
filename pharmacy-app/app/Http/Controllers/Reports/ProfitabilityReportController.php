<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Item;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleLine;
use App\Modules\Sales\Models\SaleReturn;
use App\Modules\Sales\Models\SaleReturnLine;
use App\Support\CsvExport;
use App\Support\CurrentBranch;
use App\Support\ReportPeriod;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What each medicine earned in the period: sold less returned, at what it
 * sold for and what its batches cost. Totals agree with the overview.
 */
class ProfitabilityReportController extends Controller
{
    public function __invoke(Request $request, CurrentBranch $branch): Response|StreamedResponse
    {
        $period = ReportPeriod::fromRequest($request);

        $sold = SaleLine::query()
            ->whereIn('sale_lines.sale_id', Sale::query()
                ->where('branch_id', $branch->id())
                ->where('status', Sale::COMPLETED)
                ->whereBetween('sold_at', $period->between())
                ->select('id'))
            ->join('batches', 'batches.id', '=', 'sale_lines.batch_id')
            ->groupBy('sale_lines.item_id')
            ->selectRaw('sale_lines.item_id, SUM(sale_lines.quantity) AS quantity, SUM(sale_lines.line_total_minor) AS revenue, SUM(sale_lines.discount_minor) AS discount, SUM(sale_lines.quantity * batches.unit_cost_minor) AS cost')
            ->toBase()
            ->get()
            ->keyBy('item_id');

        // Returns in the period, whenever the sale was: as the overview does.
        $returned = SaleReturnLine::query()
            ->whereIn('sale_return_id', SaleReturn::query()
                ->where('branch_id', $branch->id())
                ->whereBetween('returned_at', $period->between())
                ->select('id'))
            ->groupBy('item_id')
            ->selectRaw('item_id, SUM(quantity) AS quantity, SUM(amount_minor) AS amount, SUM(CASE WHEN restocked THEN cost_minor ELSE 0 END) AS restocked_cost')
            ->toBase()
            ->get()
            ->keyBy('item_id');

        $ids = $sold->keys()->merge($returned->keys())->unique();
        $items = Item::query()->whereIn('id', $ids)->get()->keyBy('id');

        $rows = $ids->map(function ($id) use ($sold, $returned, $items) {
            $s = $sold->get($id);
            $r = $returned->get($id);
            $revenue = (int) ($s->revenue ?? 0) - (int) ($r->amount ?? 0);
            $cost = (int) ($s->cost ?? 0) - (int) ($r->restocked_cost ?? 0);

            return [
                'id' => (string) $id,
                'medicine' => $items->get($id)->name ?? '',
                'quantity' => (int) ($s->quantity ?? 0) - (int) ($r->quantity ?? 0),
                'returnedQuantity' => (int) ($r->quantity ?? 0),
                'discountMinor' => (int) ($s->discount ?? 0),
                'revenueMinor' => $revenue,
                'costMinor' => $cost,
                'profitMinor' => $revenue - $cost,
            ];
        })->sortByDesc('profitMinor')->values();

        $props = [
            ...$period->toArray(),
            'rows' => $rows,
            'totals' => [
                'revenueMinor' => (int) $rows->sum('revenueMinor'),
                'costMinor' => (int) $rows->sum('costMinor'),
                'profitMinor' => (int) $rows->sum('profitMinor'),
                'discountMinor' => (int) $rows->sum('discountMinor'),
            ],
        ];

        return match ($request->query('export')) {
            null => Inertia::render('reports/profitability', $props),
            'medicines' => CsvExport::download("profitability-{$period->from->toDateString()}-to-{$period->to->toDateString()}", [
                'Medicine' => 'medicine',
                'Quantity sold' => 'quantity',
                'Quantity returned' => 'returnedQuantity',
                'Discounts' => 'discountMinor',
                'Revenue' => 'revenueMinor',
                'Cost' => 'costMinor',
                'Profit' => 'profitMinor',
            ], $rows),
            default => abort(404),
        };
    }
}
