<?php

namespace App\Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Procurement\Actions\ReturnGoods;
use App\Modules\Procurement\Models\GoodsReceipt;
use App\Modules\Procurement\Models\GoodsReceiptLine;
use App\Modules\Procurement\Models\SupplierReturn;
use App\Modules\Procurement\Models\SupplierReturnLine;
use App\Support\CurrentBranch;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Goods sent back to suppliers from the branch being worked in.
 */
class SupplierReturnController extends Controller
{
    public function __construct(private readonly CurrentBranch $branch) {}

    public function index(): Response
    {
        return Inertia::render('supplier-returns/index', [
            'returns' => SupplierReturn::query()
                ->where('branch_id', $this->branch->id())
                ->with(['supplier:id,name', 'receipt:id,number'])
                ->orderByDesc('returned_on')
                ->orderByDesc('number')
                ->limit(200)
                ->get()
                ->map(fn (SupplierReturn $return) => [
                    'id' => $return->id,
                    'number' => $return->number,
                    'supplier' => $return->supplier->name,
                    'receipt' => $return->receipt->number,
                    'returnedOn' => $return->returned_on->toDateString(),
                    'reason' => $return->reason,
                    'totalMinor' => $return->total_minor,
                ]),
        ]);
    }

    public function show(SupplierReturn $return): Response
    {
        abort_unless($return->branch_id === $this->branch->id(), 404);
        $return->load(['supplier:id,name', 'receipt:id,number', 'returner:id,name', 'lines.item', 'lines.batch']);

        return Inertia::render('supplier-returns/show', [
            'supplierReturn' => [
                'id' => $return->id,
                'number' => $return->number,
                'supplier' => $return->supplier->name,
                'receipt' => ['id' => $return->receipt->id, 'number' => $return->receipt->number],
                'returnedOn' => $return->returned_on->toDateString(),
                'returnedBy' => $return->returner->name,
                'reason' => $return->reason,
                'totalMinor' => $return->total_minor,
                'lines' => $return->lines->map(fn (SupplierReturnLine $line) => [
                    'id' => $line->id,
                    'medicine' => $line->item->name,
                    'batchNumber' => $line->batch->batch_number,
                    'quantity' => $line->quantity,
                    'unitCostMinor' => $line->unit_cost_minor,
                    'lineTotalMinor' => $line->line_total_minor,
                ]),
            ],
        ]);
    }

    /**
     * Choosing what goes back from one delivery.
     */
    public function create(GoodsReceipt $receipt): Response
    {
        abort_unless($receipt->branch_id === $this->branch->id(), 404);
        $receipt->load(['supplier:id,name', 'lines.item', 'lines.batch']);
        $returned = $this->returned($receipt);

        return Inertia::render('supplier-returns/create', [
            'receipt' => [
                'id' => $receipt->id,
                'number' => $receipt->number,
                'supplier' => $receipt->supplier->name,
                'receivedOn' => $receipt->received_on->toDateString(),
                'lines' => $receipt->lines->map(fn (GoodsReceiptLine $line) => [
                    'id' => $line->id,
                    'medicine' => $line->item->name,
                    'batchNumber' => $line->batch_number,
                    'expiresOn' => $line->expires_on->toDateString(),
                    'received' => $line->quantity,
                    'returnable' => min($line->quantity - (int) ($returned[$line->id] ?? 0), $line->batch->quantity_on_hand),
                    'unitCostMinor' => $line->unit_cost_minor,
                ]),
            ],
            'today' => CarbonImmutable::today()->toDateString(),
        ]);
    }

    public function store(Request $request, GoodsReceipt $receipt, ReturnGoods $returnGoods): RedirectResponse
    {
        abort_unless($receipt->branch_id === $this->branch->id(), 404);
        $validated = $request->validate([
            'returned_on' => ['required', 'date', 'before_or_equal:today', 'after_or_equal:'.$receipt->received_on->toDateString()],
            'reason' => ['required', 'string', 'max:255'],
            'quantities' => ['required', 'array', 'max:200'],
            'quantities.*' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ]);

        $quantities = [];
        foreach ($validated['quantities'] as $lineId => $quantity) {
            if (! Str::isUuid((string) $lineId)) {
                throw ValidationException::withMessages(['quantities' => 'An item is not on this delivery.']);
            }
            $quantities[(string) $lineId] = (int) $quantity;
        }

        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $return = $returnGoods->handle($user, $receipt, $quantities, $validated['reason'], CarbonImmutable::parse($validated['returned_on']));

        return to_route('supplier-returns.show', $return)->with('success', "Return {$return->number} recorded; Finance takes it off what is owed.");
    }

    /**
     * Quantities already sent back, by goods receipt line.
     *
     * @return array<string, int>
     */
    public static function returned(GoodsReceipt $receipt): array
    {
        return SupplierReturnLine::query()
            ->whereIn('goods_receipt_line_id', $receipt->lines->pluck('id'))
            ->groupBy('goods_receipt_line_id')
            ->selectRaw('goods_receipt_line_id, SUM(quantity) AS quantity')
            ->pluck('quantity', 'goods_receipt_line_id')
            ->map(fn ($quantity) => (int) $quantity)
            ->all();
    }
}
