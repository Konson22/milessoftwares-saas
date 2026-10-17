<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Item;
use App\Modules\MasterData\Models\Supplier;
use App\Modules\Procurement\Models\GoodsReceipt;
use App\Modules\Procurement\Models\GoodsReceiptLine;
use App\Modules\Procurement\Models\SupplierReturn;
use App\Support\CurrentBranch;
use App\Support\ReportPeriod;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What the branch bought in the period: per supplier (received less sent
 * back), per medicine (quantity and average cost), and each delivery.
 * What is still owed to suppliers lives in Finance payables.
 */
class PurchaseReportController extends Controller
{
    public const MAX_RECEIPTS = 200;

    public function __invoke(Request $request, CurrentBranch $branch): Response
    {
        $period = ReportPeriod::fromRequest($request);
        $dates = [$period->from->toDateString(), $period->to->toDateString()];

        $receipts = fn () => GoodsReceipt::query()
            ->where('branch_id', $branch->id())
            ->whereBetween('received_on', $dates);

        $received = $receipts()
            ->groupBy('supplier_id')
            ->selectRaw('supplier_id, COUNT(*) AS receipts, SUM(total_minor) AS total')
            ->toBase()
            ->get()
            ->keyBy('supplier_id');
        $sentBack = SupplierReturn::query()
            ->where('branch_id', $branch->id())
            ->whereBetween('returned_on', $dates)
            ->groupBy('supplier_id')
            ->selectRaw('supplier_id, COUNT(*) AS returns, SUM(total_minor) AS total')
            ->toBase()
            ->get()
            ->keyBy('supplier_id');

        $supplierIds = $received->keys()->merge($sentBack->keys())->unique();
        $suppliers = Supplier::query()->whereIn('id', $supplierIds)->pluck('name', 'id');

        $bySupplier = $supplierIds->map(fn ($id) => [
            'id' => (string) $id,
            'supplier' => (string) ($suppliers[$id] ?? ''),
            'receipts' => (int) ($received->get($id)->receipts ?? 0),
            'receivedMinor' => (int) ($received->get($id)->total ?? 0),
            'returnedMinor' => (int) ($sentBack->get($id)->total ?? 0),
            'netMinor' => (int) ($received->get($id)->total ?? 0) - (int) ($sentBack->get($id)->total ?? 0),
        ])->sortByDesc('netMinor')->values();

        $byItem = GoodsReceiptLine::query()
            ->whereIn('goods_receipt_id', $receipts()->select('id'))
            ->groupBy('item_id')
            ->selectRaw('item_id, SUM(quantity) AS quantity, SUM(line_total_minor) AS total, MIN(unit_cost_minor) AS lowest, MAX(unit_cost_minor) AS highest')
            ->orderByDesc('total')
            ->toBase()
            ->get();
        $items = Item::query()->whereIn('id', $byItem->pluck('item_id'))->pluck('name', 'id');

        return Inertia::render('reports/purchases', [
            ...$period->toArray(),
            'bySupplier' => $bySupplier,
            'byItem' => $byItem->map(fn (object $row) => [
                'id' => (string) $row->item_id,
                'medicine' => (string) ($items[$row->item_id] ?? ''),
                'quantity' => (int) $row->quantity,
                'totalMinor' => (int) $row->total,
                // Per base unit, so different pack sizes compare.
                'averageCostMinor' => (int) $row->quantity === 0 ? 0 : intdiv((int) $row->total, (int) $row->quantity),
                'lowestCostMinor' => (int) $row->lowest,
                'highestCostMinor' => (int) $row->highest,
            ]),
            'receipts' => $receipts()
                ->with('supplier:id,name')
                ->latest('received_on')
                ->latest('created_at')
                ->limit(self::MAX_RECEIPTS)
                ->get()
                ->map(fn (GoodsReceipt $receipt) => [
                    'id' => $receipt->id,
                    'number' => $receipt->number,
                    'receivedOn' => $receipt->received_on->toDateString(),
                    'supplier' => $receipt->supplier->name,
                    'supplierReference' => $receipt->supplier_reference,
                    'totalMinor' => $receipt->total_minor,
                ]),
            'totals' => [
                'receivedMinor' => (int) $bySupplier->sum('receivedMinor'),
                'returnedMinor' => (int) $bySupplier->sum('returnedMinor'),
                'netMinor' => (int) $bySupplier->sum('netMinor'),
            ],
        ]);
    }
}
