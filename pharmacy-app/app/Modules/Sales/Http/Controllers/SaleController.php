<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Inventory\Actions\InsufficientStock;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Item;
use App\Modules\Sales\Actions\ReturnSale;
use App\Modules\Sales\Actions\Sell;
use App\Modules\Sales\Actions\Tills;
use App\Modules\Sales\Actions\VoidSale;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleLine;
use App\Modules\Sales\Models\SaleReturn;
use App\Modules\Sales\Models\SaleReturnLine;
use App\Support\CurrentBranch;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Access\CurrentAccess;

/**
 * The counter of the branch being worked in.
 */
class SaleController extends Controller
{
    public function __construct(private readonly CurrentBranch $branch) {}

    public function index(Request $request): Response
    {
        $day = CarbonImmutable::parse(is_string($request->query('day')) ? $request->query('day') : 'today')->startOfDay();

        $sales = Sale::query()
            ->where('branch_id', $this->branch->id())
            ->whereBetween('sold_at', [$day, $day->endOfDay()])
            ->with('seller:id,name')
            ->latest('sold_at')
            ->get();
        $returns = (int) SaleReturn::query()
            ->where('branch_id', $this->branch->id())
            ->whereBetween('returned_at', [$day, $day->endOfDay()])
            ->sum('total_minor');

        return Inertia::render('sales/index', [
            'day' => $day->toDateString(),
            'sales' => $sales->map(fn (Sale $sale) => [
                'id' => $sale->id,
                'number' => $sale->number,
                'soldAt' => $sale->sold_at->toIso8601String(),
                'soldBy' => $sale->seller->name,
                'totalMinor' => $sale->total_minor,
                'paymentMethod' => $sale->payment_method->label(),
                'status' => $sale->status,
            ]),
            // Money given back for returns that day comes off the takings.
            'takings' => [
                'count' => $sales->where('status', Sale::COMPLETED)->count(),
                'totalMinor' => (int) $sales->where('status', Sale::COMPLETED)->sum('total_minor') - $returns,
                'returnsMinor' => $returns,
            ],
        ]);
    }

    /**
     * The counter: what can be sold here today, with prices.
     */
    public function create(Request $request, Tills $tills): Response
    {
        $till = $tills->openFor($this->user($request), $this->branch->id());

        $sellable = Batch::query()
            ->where('branch_id', $this->branch->id())
            ->where('quantity_on_hand', '>', 0)
            ->whereDate('expires_on', '>', CarbonImmutable::today())
            ->groupBy('item_id')
            ->selectRaw('item_id, SUM(quantity_on_hand) AS quantity')
            ->pluck('quantity', 'item_id');

        return Inertia::render('sales/create', [
            'medicines' => Item::query()
                ->where('active', true)
                ->orderBy('name')
                ->get()
                ->map(fn (Item $item) => [
                    'id' => $item->id,
                    'label' => $item->name,
                    'genericName' => $item->alias ?? '',
                    'barcode' => $item->barcode,
                    'unit' => $item->unit,
                    'priceMinor' => $item->sell_price_minor,
                    'available' => (int) ($sellable[$item->id] ?? 0),
                    'prescriptionOnly' => $item->restricted,
                ]),
            'maxDiscountPercent' => $this->maxDiscountPercent(),
            'till' => $till === null ? null : ['id' => $till->id, 'number' => $till->number],
            'paymentMethods' => array_map(fn (PaymentMethod $method) => ['value' => $method->value, 'label' => $method->label()], PaymentMethod::cases()),
        ]);
    }

    public function store(Request $request, Sell $sell): RedirectResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.item_id' => ['required', 'uuid', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'items.*.discount_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'submission_id' => ['nullable', 'uuid'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'payment_reference' => ['nullable', 'string', 'max:64'],
            'amount_tendered' => ['nullable', 'string', Money::rule()],
            'customer_name' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $sale = $sell->handle(
                by: $this->user($request),
                branchId: $this->branch->id(),
                items: array_values(array_map(fn (array $item) => [
                    'item_id' => (string) $item['item_id'],
                    'quantity' => (int) $item['quantity'],
                    'discount_percent' => (int) ($item['discount_percent'] ?? 0),
                ], $validated['items'])),
                method: PaymentMethod::from($validated['payment_method']),
                paymentReference: $validated['payment_reference'] ?? null,
                tenderedMinor: isset($validated['amount_tendered']) ? Money::toMinor($validated['amount_tendered']) : null,
                customerName: $validated['customer_name'] ?? null,
                maxDiscountPercent: $this->maxDiscountPercent(),
                submissionId: $validated['submission_id'] ?? null,
            );
        } catch (InsufficientStock $e) {
            $index = array_search($e->item->id, array_column($validated['items'], 'item_id'), true);

            throw ValidationException::withMessages(['items.'.($index === false ? 0 : $index).'.quantity' => $e->getMessage()]);
        }

        return to_route('sales.show', $sale)->with('success', "Sale {$sale->number} completed.");
    }

    public function show(Sale $sale): Response
    {
        $this->ensureHere($sale);
        $sale->load(['seller:id,name', 'voider:id,name', 'lines.item', 'lines.batch:id,batch_number,expires_on', 'returns.returner:id,name', 'returns.lines.item']);
        $returned = $sale->returns->flatMap(fn (SaleReturn $return) => $return->lines)->groupBy('sale_line_id')->map(fn ($lines) => (int) $lines->sum('quantity'));
        $mayRefund = app(CurrentAccess::class)->get()?->allowsIn('pharmacy.sales.void', branchId: $this->branch->id()) ?? false;

        return Inertia::render('sales/show', [
            'sale' => [
                'id' => $sale->id,
                'number' => $sale->number,
                'status' => $sale->status,
                'soldAt' => $sale->sold_at->toIso8601String(),
                'soldBy' => $sale->seller->name,
                'customerName' => $sale->customer_name,
                'currency' => $sale->currency,
                'totalMinor' => $sale->total_minor,
                'discountMinor' => $sale->discount_minor,
                'paymentMethod' => $sale->payment_method->label(),
                'paymentReference' => $sale->payment_reference,
                'tenderedMinor' => $sale->amount_tendered_minor,
                'changeMinor' => $sale->change_minor,
                'voidedAt' => $sale->voided_at?->toIso8601String(),
                'voidedBy' => $sale->voider?->name,
                'voidReason' => $sale->void_reason,
                'lines' => $sale->lines->map(fn (SaleLine $line) => [
                    'id' => $line->id,
                    'medicine' => $line->item->name,
                    'batchNumber' => $line->batch->batch_number,
                    'expiresOn' => $line->batch->expires_on->toDateString(),
                    'quantity' => $line->quantity,
                    'unitPriceMinor' => $line->unit_price_minor,
                    'discountMinor' => $line->discount_minor,
                    'lineTotalMinor' => $line->line_total_minor,
                    'returned' => $returned->get($line->id, 0),
                ]),
                'returns' => $sale->returns->map(fn (SaleReturn $return) => [
                    'id' => $return->id,
                    'number' => $return->number,
                    'returnedAt' => $return->returned_at->toIso8601String(),
                    'returnedBy' => $return->returner->name,
                    'reason' => $return->reason,
                    'totalMinor' => $return->total_minor,
                    'lines' => $return->lines->map(fn (SaleReturnLine $line) => [
                        'id' => $line->id,
                        'medicine' => $line->item->name,
                        'quantity' => $line->quantity,
                        'amountMinor' => $line->amount_minor,
                        'restocked' => $line->restocked,
                    ]),
                ]),
            ],
            // A sale partly returned is finished by returning the rest.
            'canVoid' => $mayRefund && $sale->status === Sale::COMPLETED && $sale->returns->isEmpty(),
            'canReturn' => $mayRefund && $sale->status === Sale::COMPLETED
                && $sale->lines->contains(fn (SaleLine $line) => $line->quantity > $returned->get($line->id, 0)),
        ]);
    }

    public function storeReturn(Request $request, Sale $sale, ReturnSale $returns): RedirectResponse
    {
        $this->ensureHere($sale);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.sale_line_id' => ['required', 'uuid', 'distinct'],
            'lines.*.quantity' => ['required', 'integer', 'min:0', 'max:100000'],
            'lines.*.restock' => ['sometimes', 'boolean'],
        ]);

        $return = $returns->handle($sale, $this->user($request), array_values(array_map(fn (array $line) => [
            'sale_line_id' => (string) $line['sale_line_id'],
            'quantity' => (int) $line['quantity'],
            'restock' => (bool) ($line['restock'] ?? false),
        ], $validated['lines'])), $validated['reason']);

        return back()->with('success', "Return {$return->number} recorded; refund {$return->currency} ".Money::toMajor($return->total_minor, $return->currency).'.');
    }

    public function void(Request $request, Sale $sale, VoidSale $void): RedirectResponse
    {
        $this->ensureHere($sale);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $void->handle($sale, $this->user($request), $validated['reason']);

        return back()->with('success', "Sale {$sale->number} voided; stock returned.");
    }

    /**
     * The customer's receipt, one line per medicine (not per batch), for
     * printing at the counter.
     */
    public function receipt(Sale $sale): Response
    {
        $this->ensureHere($sale);
        $sale->load(['seller:id,name', 'lines.item']);

        return Inertia::render('sales/receipt', [
            'sale' => [
                'id' => $sale->id,
                'number' => $sale->number,
                'status' => $sale->status,
                'soldAt' => $sale->sold_at->toIso8601String(),
                'soldBy' => $sale->seller->name,
                'customerName' => $sale->customer_name,
                'totalMinor' => $sale->total_minor,
                'discountMinor' => $sale->discount_minor,
                'paymentMethod' => $sale->payment_method->label(),
                'paymentReference' => $sale->payment_reference,
                'tenderedMinor' => $sale->amount_tendered_minor,
                'changeMinor' => $sale->change_minor,
                'lines' => $sale->lines->groupBy('item_id')->map(fn ($lines) => [
                    'medicine' => $lines->first()->item->name,
                    'quantity' => (int) $lines->sum('quantity'),
                    'unitPriceMinor' => $lines->first()->unit_price_minor,
                    'discountMinor' => (int) $lines->sum('discount_minor'),
                    'lineTotalMinor' => (int) $lines->sum('line_total_minor'),
                ])->values(),
            ],
        ]);
    }

    /**
     * How much the signed-in member may take off a line in this branch.
     */
    private function maxDiscountPercent(): int
    {
        $allowed = app(CurrentAccess::class)->get()?->allowsIn('pharmacy.sales.discount', branchId: $this->branch->id()) ?? false;

        return $allowed ? max(0, min(100, (int) config('pharmacy.max_discount_percent'))) : 0;
    }

    /**
     * Sales of other branches look unknown.
     */
    private function ensureHere(Sale $sale): void
    {
        abort_unless($sale->branch_id === $this->branch->id(), 404);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
