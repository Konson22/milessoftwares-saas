<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleLine;
use App\Modules\Sales\Models\SaleReturn;
use App\Modules\Sales\Models\SaleReturnLine;
use App\Support\CsvExport;
use App\Support\CurrentBranch;
use App\Support\Industry;
use App\Support\ReportPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The period's sales cut two ways: by category and by cashier, each sold
 * less returned, with what it cost and the margin. Returns count in the
 * period they were taken, against the cashier who made the sale, so both
 * cuts add up to the overview's takings and the profitability report.
 */
class SalesAnalysisReportController extends Controller
{
    public const UNCATEGORISED = 'Uncategorised';

    public function __invoke(Request $request, CurrentBranch $branch, Industry $industry): Response|StreamedResponse
    {
        $period = ReportPeriod::fromRequest($request);

        $sales = fn () => Sale::query()
            ->where('branch_id', $branch->id())
            ->where('application', $industry->code())
            ->where('status', Sale::COMPLETED)
            ->whereBetween('sold_at', $period->between());
        $returns = fn () => SaleReturn::query()
            ->where('branch_id', $branch->id())
            ->whereRelation('sale', 'application', $industry->code())
            ->whereBetween('returned_at', $period->between());

        $category = "COALESCE(NULLIF(TRIM(items.category), ''), '".self::UNCATEGORISED."')";
        $byCategory = $this->rows($this->sold($sales(), $category), $this->returned($returns(), $category), fn (string $key) => $key)
            ->sortByDesc('revenueMinor')
            ->values();

        $sold = $this->sold($sales(), 'sales.sold_by');
        $returned = $this->returned($returns(), 'sales.sold_by');
        $names = User::query()->whereIn('id', $sold->keys()->merge($returned->keys())->unique())->pluck('name', 'id');
        $byCashier = $this->rows($sold, $returned, fn (string $key) => (string) ($names[$key] ?? ''))
            ->sortBy('name')
            ->values();

        $props = [
            ...$period->toArray(),
            'byCategory' => $byCategory,
            'byCashier' => $byCashier,
            'totals' => [
                'revenueMinor' => (int) $byCategory->sum('revenueMinor'),
                'costMinor' => (int) $byCategory->sum('costMinor'),
                'profitMinor' => (int) $byCategory->sum('profitMinor'),
                'discountMinor' => (int) $byCategory->sum('discountMinor'),
            ],
        ];
        $name = "{$period->from->toDateString()}-to-{$period->to->toDateString()}";
        $columns = fn (string $first) => [$first => 'name', 'Sales' => 'sales', 'Quantity sold' => 'quantity', 'Quantity returned' => 'returnedQuantity', 'Discounts' => 'discountMinor', 'Revenue' => 'revenueMinor', 'Cost' => 'costMinor', 'Profit' => 'profitMinor', 'Margin %' => 'marginPercent'];

        return match ($request->query('export')) {
            null => Inertia::render($industry->page('reports/sales'), $props),
            'categories' => CsvExport::download("sales-by-category-{$name}", $columns('Category'), $byCategory),
            'cashiers' => CsvExport::download("sales-by-cashier-{$name}", $columns('Cashier'), $byCashier),
            default => abort(404),
        };
    }

    /**
     * What the sales sold, per group: a column or expression over the
     * sale, its items and batches.
     *
     * @param  Builder<Sale>  $sales
     * @param  literal-string  $group
     * @return Collection<string, stdClass>
     */
    private function sold(Builder $sales, string $group): Collection
    {
        return SaleLine::query()
            ->whereIn('sale_lines.sale_id', $sales->select('id'))
            ->join('sales', 'sales.id', '=', 'sale_lines.sale_id')
            ->join('items', 'items.id', '=', 'sale_lines.item_id')
            ->join('batches', 'batches.id', '=', 'sale_lines.batch_id')
            ->groupBy(DB::raw($group))
            ->selectRaw("{$group} AS group_key, COUNT(DISTINCT sale_lines.sale_id) AS sales, SUM(sale_lines.quantity) AS quantity, SUM(sale_lines.line_total_minor) AS revenue, SUM(sale_lines.discount_minor) AS discount, SUM(sale_lines.quantity * batches.unit_cost_minor) AS cost")
            ->toBase()
            ->get()
            ->keyBy(fn (stdClass $row) => (string) $row->group_key);
    }

    /**
     * What came back from the returns, per group as in sold().
     *
     * @param  Builder<SaleReturn>  $returns
     * @param  literal-string  $group
     * @return Collection<string, stdClass>
     */
    private function returned(Builder $returns, string $group): Collection
    {
        return SaleReturnLine::query()
            ->whereIn('sale_return_lines.sale_return_id', $returns->select('id'))
            ->join('sale_returns', 'sale_returns.id', '=', 'sale_return_lines.sale_return_id')
            ->join('sales', 'sales.id', '=', 'sale_returns.sale_id')
            ->join('items', 'items.id', '=', 'sale_return_lines.item_id')
            ->groupBy(DB::raw($group))
            ->selectRaw("{$group} AS group_key, SUM(sale_return_lines.quantity) AS quantity, SUM(sale_return_lines.amount_minor) AS amount, SUM(CASE WHEN sale_return_lines.restocked THEN sale_return_lines.cost_minor ELSE 0 END) AS restocked_cost")
            ->toBase()
            ->get()
            ->keyBy(fn (stdClass $row) => (string) $row->group_key);
    }

    /**
     * One row per group: what it sold less what came back.
     *
     * @param  Collection<string, stdClass>  $sold
     * @param  Collection<string, stdClass>  $returned
     * @param  callable(string): string  $name
     * @return Collection<int, array{id: string, name: string, sales: int, quantity: int, returnedQuantity: int, discountMinor: int, revenueMinor: int, costMinor: int, profitMinor: int, marginPercent: float|null}>
     */
    private function rows(Collection $sold, Collection $returned, callable $name): Collection
    {
        return $sold->keys()->merge($returned->keys())->unique()->values()->map(function (string $key) use ($sold, $returned, $name) {
            $s = $sold->get($key);
            $r = $returned->get($key);
            $revenue = (int) ($s->revenue ?? 0) - (int) ($r->amount ?? 0);
            $cost = (int) ($s->cost ?? 0) - (int) ($r->restocked_cost ?? 0);

            return [
                'id' => $key,
                'name' => $name($key),
                'sales' => (int) ($s->sales ?? 0),
                'quantity' => (int) ($s->quantity ?? 0) - (int) ($r->quantity ?? 0),
                'returnedQuantity' => (int) ($r->quantity ?? 0),
                'discountMinor' => (int) ($s->discount ?? 0),
                'revenueMinor' => $revenue,
                'costMinor' => $cost,
                'profitMinor' => $revenue - $cost,
                // Of the net takings; none when nothing was taken.
                'marginPercent' => $revenue > 0 ? round(($revenue - $cost) * 100 / $revenue, 1) : null,
            ];
        });
    }
}
