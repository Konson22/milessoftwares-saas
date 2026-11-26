<?php

namespace App\Http\Controllers;

use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Item;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleLine;
use App\Modules\Sales\Models\SaleReturn;
use App\Modules\Sales\Models\SaleReturnLine;
use App\Support\CsvExport;
use App\Support\CurrentBranch;
use App\Support\Industry;
use App\Support\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The branch's figures for a period: takings, what they cost, how they were
 * paid, what sold best; and what the stock on the shelves is worth today.
 * Cost is the batch's unit cost, so profit follows the stock actually sold;
 * returns come off both. Only this industry's sales and stock (Industry): a
 * branch that runs both reports each on its own.
 */
class ReportController extends Controller
{
    public const TOP_MEDICINES = 10;

    public function __invoke(Request $request, CurrentBranch $branch, Industry $industry): Response|StreamedResponse
    {
        $period = ReportPeriod::fromRequest($request);
        $today = CarbonImmutable::today();
        [$from, $to] = [$period->from, $period->to];

        $sales = fn (string $status) => Sale::query()
            ->where('branch_id', $branch->id())
            ->where('application', $industry->code())
            ->where('status', $status)
            ->whereBetween('sold_at', [$from->startOfDay(), $to->endOfDay()]);

        // Lines of the period's completed sales, each priced at its batch's cost.
        $lines = fn () => SaleLine::query()
            ->whereIn('sale_lines.sale_id', $sales(Sale::COMPLETED)->select('id'))
            ->join('batches', 'batches.id', '=', 'sale_lines.batch_id');

        // Returns in the period: their refunds come off the takings, and
        // what went back on the shelf off the cost.
        $returns = fn () => SaleReturn::query()
            ->where('branch_id', $branch->id())
            ->whereRelation('sale', 'application', $industry->code())
            ->whereBetween('returned_at', [$from->startOfDay(), $to->endOfDay()]);
        $returnedMinor = (int) $returns()->sum('total_minor');
        $restockedCost = (int) SaleReturnLine::query()
            ->whereIn('sale_return_id', $returns()->select('id'))
            ->where('restocked', true)
            ->sum('cost_minor');

        $revenue = (int) $sales(Sale::COMPLETED)->sum('total_minor') - $returnedMinor;
        $count = $sales(Sale::COMPLETED)->count();
        $cost = (int) $lines()->sum(DB::raw('sale_lines.quantity * batches.unit_cost_minor')) - $restockedCost;

        // Every table below is net of the period's returns, as the takings
        // are, so the tables add up to the summary.
        $returnedLines = fn () => SaleReturnLine::query()->whereIn('sale_return_id', $returns()->select('id'));
        $returnedByItem = $returnedLines()
            ->groupBy('item_id')
            ->selectRaw('item_id, SUM(quantity) AS quantity, SUM(amount_minor) AS amount, SUM(CASE WHEN restocked THEN cost_minor ELSE 0 END) AS cost')
            ->toBase()
            ->get()
            ->keyBy('item_id');

        $top = $lines()
            ->groupBy('sale_lines.item_id')
            ->selectRaw('sale_lines.item_id, SUM(sale_lines.quantity) AS quantity, SUM(sale_lines.line_total_minor) AS revenue, SUM(sale_lines.quantity * batches.unit_cost_minor) AS cost')
            ->toBase()
            ->get()
            ->map(function (object $row) use ($returnedByItem) {
                $back = $returnedByItem->get((string) $row->item_id);

                return (object) [
                    'item_id' => (string) $row->item_id,
                    'quantity' => (int) $row->quantity - (int) ($back->quantity ?? 0),
                    'revenue' => (int) $row->revenue - (int) ($back->amount ?? 0),
                    'cost' => (int) $row->cost - (int) ($back->cost ?? 0),
                ];
            })
            ->sortByDesc('revenue')
            ->take(self::TOP_MEDICINES)
            ->values();
        $items = Item::query()->whereIn('id', $top->pluck('item_id'))->get()->keyBy('id');

        $refundsByMethod = $returns()
            ->groupBy('refund_method')
            ->selectRaw('refund_method, SUM(total_minor) AS total')
            ->toBase()
            ->pluck('total', 'refund_method');
        $refundsByDay = $returns()
            ->groupBy(DB::raw('DATE(returned_at)'))
            ->selectRaw('DATE(returned_at) AS day, SUM(total_minor) AS total')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row) => [substr((string) $row->day, 0, 10) => (int) $row->total]);

        $props = [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'summary' => [
                'count' => $count,
                'revenueMinor' => $revenue,
                'costMinor' => $cost,
                'profitMinor' => $revenue - $cost,
                'averageMinor' => $count === 0 ? 0 : intdiv($revenue, $count),
                'voidedCount' => $sales(Sale::VOIDED)->count(),
                'voidedMinor' => (int) $sales(Sale::VOIDED)->sum('total_minor'),
                'returnedCount' => $returns()->count(),
                'returnedMinor' => $returnedMinor,
            ],
            // Refunds come off the method they were paid back by.
            'byMethod' => $this->net(
                $sales(Sale::COMPLETED)
                    ->groupBy('payment_method')
                    ->selectRaw('payment_method AS bucket, COUNT(*) AS count, SUM(total_minor) AS total')
                    ->toBase()
                    ->get(),
                $refundsByMethod,
            )
                ->sortByDesc('totalMinor')
                ->values()
                ->map(fn (array $row) => [
                    'method' => PaymentMethod::from($row['bucket'])->label(),
                    'count' => $row['count'],
                    'totalMinor' => $row['totalMinor'],
                ]),
            // Refunds come off the day they were given.
            'byDay' => $this->net(
                $sales(Sale::COMPLETED)
                    ->groupBy(DB::raw('DATE(sold_at)'))
                    ->selectRaw('DATE(sold_at) AS bucket, COUNT(*) AS count, SUM(total_minor) AS total')
                    ->toBase()
                    ->get(),
                $refundsByDay,
                fn (string $day) => substr($day, 0, 10),
            )
                ->sortBy('bucket')
                ->values()
                ->map(fn (array $row) => [
                    'day' => $row['bucket'],
                    'count' => $row['count'],
                    'totalMinor' => $row['totalMinor'],
                ]),
            'topItems' => $top->map(fn (object $row) => [
                'id' => (string) $row->item_id,
                'item' => $items->get((string) $row->item_id)->name ?? '',
                'quantity' => (int) $row->quantity,
                'revenueMinor' => (int) $row->revenue,
                'costMinor' => (int) $row->cost,
            ]),
            'stock' => $this->stockValue($branch->id(), $industry->itemKinds(), $today),
        ];
        $name = "{$period->from->toDateString()}-to-{$period->to->toDateString()}";

        return match ($request->query('export')) {
            null => Inertia::render($industry->page('reports/index'), $props),
            'days' => CsvExport::download("sales-by-day-{$name}", ['Day' => 'day', 'Sales' => 'count', 'Takings' => 'totalMinor'], $props['byDay']),
            'methods' => CsvExport::download("sales-by-payment-{$name}", ['Payment method' => 'method', 'Sales' => 'count', 'Takings' => 'totalMinor'], $props['byMethod']),
            'top' => CsvExport::download("top-items-{$name}", [$industry->itemLabel() => 'item', 'Quantity' => 'quantity', 'Revenue' => 'revenueMinor', 'Cost' => 'costMinor'], $props['topItems']),
            default => abort(404),
        };
    }

    /**
     * Sales grouped by a bucket (a day, a payment method), less the refunds
     * in the same bucket. A bucket with refunds but no sales still shows,
     * with no sales and a negative total.
     *
     * @param  Collection<int, stdClass>  $sales  rows of bucket, count, total
     * @param  Collection<array-key, mixed>  $refunds  refunded total by bucket
     * @param  (callable(string): string)|null  $key  normalises a bucket read from the database
     * @return Collection<string, array{bucket: string, count: int, totalMinor: int}>
     */
    private function net(Collection $sales, Collection $refunds, ?callable $key = null): Collection
    {
        $key ??= fn (string $bucket) => $bucket;
        $rows = $sales->mapWithKeys(fn (stdClass $row) => [$key((string) $row->bucket) => [
            'bucket' => $key((string) $row->bucket),
            'count' => (int) $row->count,
            'totalMinor' => (int) $row->total,
        ]]);

        foreach ($refunds as $bucket => $refunded) {
            $bucket = $key((string) $bucket);
            $row = $rows->get($bucket, ['bucket' => $bucket, 'count' => 0, 'totalMinor' => 0]);
            $row['totalMinor'] -= (int) $refunded;
            $rows->put($bucket, $row);
        }

        return $rows;
    }

    /**
     * What the stock on the shelves is worth today, at cost and at today's
     * prices; expired stock separately, at cost, as it can only be lost, and
     * unexpired stock on hold (quarantined or recalled) at cost.
     *
     * @param  list<string>  $kinds  the item kinds of this industry
     * @return array{units: int, costMinor: int, retailMinor: int, expiredUnits: int, expiredCostMinor: int, heldUnits: int, heldCostMinor: int}
     */
    private function stockValue(string $branchId, array $kinds, CarbonImmutable $today): array
    {
        $onHand = fn () => Batch::query()
            ->where('batches.branch_id', $branchId)
            ->where('batches.quantity_on_hand', '>', 0)
            ->join('items', 'items.id', '=', 'batches.item_id')
            ->whereIn('items.kind', $kinds);

        $sellable = $onHand()
            ->where('batches.status', Batch::AVAILABLE)
            ->unexpired($today)
            ->selectRaw('SUM(batches.quantity_on_hand) AS units, SUM(batches.quantity_on_hand * batches.unit_cost_minor) AS cost, SUM(batches.quantity_on_hand * items.sell_price_minor) AS retail')
            ->toBase()
            ->first();

        $expired = $onHand()
            ->whereDate('batches.expires_on', '<=', $today)
            ->selectRaw('SUM(batches.quantity_on_hand) AS units, SUM(batches.quantity_on_hand * batches.unit_cost_minor) AS cost')
            ->toBase()
            ->first();

        $held = $onHand()
            ->where('batches.status', '!=', Batch::AVAILABLE)
            ->unexpired($today)
            ->selectRaw('SUM(batches.quantity_on_hand) AS units, SUM(batches.quantity_on_hand * batches.unit_cost_minor) AS cost')
            ->toBase()
            ->first();

        return [
            'units' => (int) ($sellable->units ?? 0),
            'costMinor' => (int) ($sellable->cost ?? 0),
            'retailMinor' => (int) ($sellable->retail ?? 0),
            'expiredUnits' => (int) ($expired->units ?? 0),
            'expiredCostMinor' => (int) ($expired->cost ?? 0),
            'heldUnits' => (int) ($held->units ?? 0),
            'heldCostMinor' => (int) ($held->cost ?? 0),
        ];
    }
}
