<?php

namespace App\Http\Controllers;

use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Item;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleLine;
use App\Modules\Sales\Models\SaleReturn;
use App\Modules\Sales\Models\SaleReturnLine;
use App\Support\CurrentBranch;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The branch's figures for a period: takings, what they cost, how they were
 * paid, what sold best; and what the stock on the shelves is worth today.
 * Cost is the batch's unit cost, so profit follows the stock actually sold;
 * returns come off both.
 */
class ReportController extends Controller
{
    /** The longest period one report covers, in days. */
    public const MAX_DAYS = 366;

    public const TOP_MEDICINES = 10;

    public function __invoke(Request $request, CurrentBranch $branch): Response
    {
        $period = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $today = CarbonImmutable::today();
        $to = isset($period['to']) ? CarbonImmutable::parse($period['to']) : $today;
        $from = isset($period['from']) ? CarbonImmutable::parse($period['from']) : $to->startOfMonth();
        // A period too long to read at a glance is cut to its last year.
        if ($from->diffInDays($to) >= self::MAX_DAYS) {
            $from = $to->subDays(self::MAX_DAYS - 1);
        }

        $sales = fn (string $status) => Sale::query()
            ->where('branch_id', $branch->id())
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
            ->whereBetween('returned_at', [$from->startOfDay(), $to->endOfDay()]);
        $returnedMinor = (int) $returns()->sum('total_minor');
        $restockedCost = (int) SaleReturnLine::query()
            ->whereIn('sale_return_id', $returns()->select('id'))
            ->where('restocked', true)
            ->sum('cost_minor');

        $revenue = (int) $sales(Sale::COMPLETED)->sum('total_minor') - $returnedMinor;
        $count = $sales(Sale::COMPLETED)->count();
        $cost = (int) $lines()->sum(DB::raw('sale_lines.quantity * batches.unit_cost_minor')) - $restockedCost;

        $top = $lines()
            ->groupBy('sale_lines.item_id')
            ->selectRaw('sale_lines.item_id, SUM(sale_lines.quantity) AS quantity, SUM(sale_lines.line_total_minor) AS revenue, SUM(sale_lines.quantity * batches.unit_cost_minor) AS cost')
            ->orderByDesc('revenue')
            ->limit(self::TOP_MEDICINES)
            ->toBase()
            ->get();
        $items = Item::query()->whereIn('id', $top->pluck('item_id'))->get()->keyBy('id');

        return Inertia::render('reports/index', [
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
            'byMethod' => $sales(Sale::COMPLETED)
                ->groupBy('payment_method')
                ->selectRaw('payment_method, COUNT(*) AS count, SUM(total_minor) AS total')
                ->orderByDesc('total')
                ->toBase()
                ->get()
                ->map(fn (object $row) => [
                    'method' => PaymentMethod::from((string) $row->payment_method)->label(),
                    'count' => (int) $row->count,
                    'totalMinor' => (int) $row->total,
                ]),
            'byDay' => $sales(Sale::COMPLETED)
                ->groupBy(DB::raw('DATE(sold_at)'))
                ->selectRaw('DATE(sold_at) AS day, COUNT(*) AS count, SUM(total_minor) AS total')
                ->orderBy('day')
                ->toBase()
                ->get()
                ->map(fn (object $row) => [
                    'day' => substr((string) $row->day, 0, 10),
                    'count' => (int) $row->count,
                    'totalMinor' => (int) $row->total,
                ]),
            'topMedicines' => $top->map(fn (object $row) => [
                'id' => (string) $row->item_id,
                'medicine' => $items->get((string) $row->item_id)->name ?? '',
                'quantity' => (int) $row->quantity,
                'revenueMinor' => (int) $row->revenue,
                'costMinor' => (int) $row->cost,
            ]),
            'stock' => $this->stockValue($branch->id(), $today),
        ]);
    }

    /**
     * What the stock on the shelves is worth today, at cost and at today's
     * prices; expired stock separately, at cost, as it can only be lost.
     *
     * @return array{units: int, costMinor: int, retailMinor: int, expiredUnits: int, expiredCostMinor: int}
     */
    private function stockValue(string $branchId, CarbonImmutable $today): array
    {
        $onHand = fn () => Batch::query()
            ->where('batches.branch_id', $branchId)
            ->where('batches.quantity_on_hand', '>', 0);

        $sellable = $onHand()
            ->whereDate('batches.expires_on', '>', $today)
            ->join('items', 'items.id', '=', 'batches.item_id')
            ->selectRaw('SUM(batches.quantity_on_hand) AS units, SUM(batches.quantity_on_hand * batches.unit_cost_minor) AS cost, SUM(batches.quantity_on_hand * items.sell_price_minor) AS retail')
            ->toBase()
            ->first();

        $expired = $onHand()
            ->whereDate('batches.expires_on', '<=', $today)
            ->selectRaw('SUM(batches.quantity_on_hand) AS units, SUM(batches.quantity_on_hand * batches.unit_cost_minor) AS cost')
            ->toBase()
            ->first();

        return [
            'units' => (int) ($sellable->units ?? 0),
            'costMinor' => (int) ($sellable->cost ?? 0),
            'retailMinor' => (int) ($sellable->retail ?? 0),
            'expiredUnits' => (int) ($expired->units ?? 0),
            'expiredCostMinor' => (int) ($expired->cost ?? 0),
        ];
    }
}
