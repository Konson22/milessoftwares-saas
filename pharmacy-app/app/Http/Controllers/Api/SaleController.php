<?php

namespace App\Http\Controllers\Api;

use App\Modules\Inventory\Actions\InsufficientStock;
use App\Modules\Pharmacy\Models\Prescription;
use App\Modules\Sales\Actions\CounterPrinting;
use App\Modules\Sales\Actions\ReturnSale;
use App\Modules\Sales\Actions\Sell;
use App\Modules\Sales\Actions\VoidSale;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Models\BranchCounterSettings;
use App\Modules\Sales\Models\DeliveryNote;
use App\Modules\Sales\Models\ItemSerial;
use App\Modules\Sales\Models\PrintedDocument;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleLine;
use App\Modules\Sales\Models\SaleReturn;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Sales at the branch's counter: made from the app (a direct sale, paid),
 * listed by day, shown as a receipt, voided and returned.
 */
class SaleController extends ApiController
{
    /**
     * GET api/v1/sales?day=: the day's sales, the member's own unless they
     * may see the branch's.
     */
    public function index(Request $request): JsonResponse
    {
        $day = CarbonImmutable::parse(is_string($request->query('day')) ? $request->query('day') : 'today')->startOfDay();
        $everyone = $this->may('sales.view');

        $sales = Sale::query()
            ->where('branch_id', $this->branch->id())
            ->where('application', $this->industry->code())
            ->when(! $everyone, fn ($query) => $query->where('sold_by', $this->user($request)->id))
            ->whereBetween('sold_at', [$day, $day->endOfDay()])
            ->with('seller:id,name')
            ->withCount('returns')
            ->latest('sold_at')
            ->limit(500)
            ->get();
        $prescriptions = Prescription::query()->whereIn('id', $sales->pluck('prescription_id')->filter())->pluck('number', 'id');

        return response()->json([
            'day' => $day->toDateString(),
            'everyone' => $everyone,
            'data' => $sales->map(fn (Sale $sale) => [
                'id' => $sale->id,
                'number' => $sale->number,
                'status' => $sale->status,
                'sold_at' => $sale->sold_at->toIso8601String(),
                'sold_by' => $sale->seller->name,
                'customer_name' => $sale->customer_name,
                'prescription_number' => $prescriptions->get((string) $sale->prescription_id),
                'payment_method' => $sale->payment_method->value,
                'payment_method_label' => $sale->payment_method->label(),
                'total_minor' => $sale->total_minor,
                'returned' => (int) $sale->getAttribute('returns_count') > 0,
            ])->values(),
        ]);
    }

    /**
     * POST api/v1/sales: a direct sale, paid now, as the web counter makes
     * it. The same submission_id again (the app retrying) answers the sale
     * already made with "duplicate": true.
     */
    public function store(Request $request, Sell $sell): JsonResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.item_id' => ['required', 'uuid', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'items.*.discount_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'items.*.pack_id' => ['nullable', 'uuid'],
            'items.*.serials' => ['sometimes', 'array', 'max:1000'],
            'items.*.serials.*' => ['nullable', 'string', 'max:64'],
            'submission_id' => ['required', 'uuid'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)->except(PaymentMethod::Account)],
            'payment_reference' => ['nullable', 'string', 'max:64'],
            'amount_tendered_minor' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:32'],
        ]);

        $settings = BranchCounterSettings::forBranch($this->branch->id(), $this->industry->code());
        if ($settings->workflow->takesOrders()) {
            throw ValidationException::withMessages(['items' => 'This branch takes an order first: use the counter on the web.']);
        }
        if (! $settings->sellsPaid()) {
            throw ValidationException::withMessages(['items' => 'This branch hands over unpaid: payment is taken at the cashier.']);
        }

        $duplicate = Sale::query()->where('submission_id', $validated['submission_id'])->exists();

        try {
            $sale = $sell->handle(
                by: $this->user($request),
                branchId: $this->branch->id(),
                items: array_values(array_map(fn (array $item) => [
                    'item_id' => (string) $item['item_id'],
                    'quantity' => (int) $item['quantity'],
                    'discount_percent' => (int) ($item['discount_percent'] ?? 0),
                    'pack_id' => isset($item['pack_id']) ? (string) $item['pack_id'] : null,
                    'serials' => array_values(array_map('strval', array_filter($item['serials'] ?? [], fn ($serial) => $serial !== null))),
                ], $validated['items'])),
                method: PaymentMethod::from($validated['payment_method']),
                paymentReference: $validated['payment_reference'] ?? null,
                tenderedMinor: isset($validated['amount_tendered_minor']) ? (int) $validated['amount_tendered_minor'] : null,
                customerName: isset($validated['customer_name']) ? trim($validated['customer_name']) : null,
                maxDiscountPercent: $this->may('sales.discount') ? $this->industry->maxDiscountPercent() : 0,
                submissionId: $validated['submission_id'],
                customerPhone: isset($validated['customer_phone']) ? trim($validated['customer_phone']) : null,
            );
        } catch (InsufficientStock $e) {
            $index = array_search($e->item->id, array_column($validated['items'], 'item_id'), true);

            throw ValidationException::withMessages(['items.'.($index === false ? 0 : $index).'.quantity' => $e->getMessage()]);
        }

        $this->ensureHere($sale);

        return response()->json(['data' => $this->present($sale), 'duplicate' => $duplicate], $duplicate ? 200 : 201);
    }

    /**
     * GET api/v1/sales/{sale}: the receipt, the lines it can be returned
     * by, and what the member may still do with it.
     */
    public function show(Request $request, Sale $sale): JsonResponse
    {
        $this->ensureVisible($request, $sale);

        return response()->json(['data' => $this->present($sale)]);
    }

    /**
     * POST api/v1/sales/{sale}/void: cancels it, refunds the payment from
     * the member's shift, and puts the stock back.
     */
    public function void(Request $request, Sale $sale, VoidSale $void): JsonResponse
    {
        $this->ensureHere($sale);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $void->handle($sale, $this->user($request), $validated['reason']);

        return response()->json(['data' => $this->present($sale->refresh())]);
    }

    /**
     * POST api/v1/sales/{sale}/returns: some of it comes back, refunded the
     * way it was paid; restocked or not.
     */
    public function storeReturn(Request $request, Sale $sale, ReturnSale $returns): JsonResponse
    {
        $this->ensureHere($sale);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.sale_line_id' => ['required', 'uuid', 'distinct'],
            'lines.*.quantity' => ['required', 'integer', 'min:0', 'max:100000'],
            'lines.*.restock' => ['sometimes', 'boolean'],
            // Of each unit back, for items that track them (lines[].tracks_serials).
            'lines.*.serials' => ['sometimes', 'array', 'max:1000'],
            'lines.*.serials.*' => ['nullable', 'string', 'max:64'],
        ]);

        $return = $returns->handle($sale, $this->user($request), array_values(array_map(fn (array $line) => [
            'sale_line_id' => (string) $line['sale_line_id'],
            'quantity' => (int) $line['quantity'],
            'restock' => (bool) ($line['restock'] ?? false),
            'serials' => array_values(array_map('strval', array_filter($line['serials'] ?? [], fn ($serial) => $serial !== null))),
        ], $validated['lines'])), $validated['reason']);

        return response()->json([
            'return' => ['id' => $return->id, 'number' => $return->number, 'total_minor' => $return->total_minor],
            'data' => $this->present($sale->refresh()),
        ], 201);
    }

    /**
     * POST api/v1/sales/{sale}/printed: the app printed or shared the
     * receipt; logged like every print at the counter.
     */
    public function printed(Request $request, Sale $sale, CounterPrinting $printing): JsonResponse
    {
        $this->ensureVisible($request, $sale);

        $document = $printing->record(PrintedDocument::RECEIPT, $sale, $this->user($request), automatic: false);

        return response()->json(['copy' => $document?->copy]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Sale $sale): array
    {
        $sale->load(['seller:id,name', 'voider:id,name', 'lines.item', 'lines.batch:id,batch_number,expires_on', 'returns.lines']);
        $returned = $sale->returns->flatMap(fn (SaleReturn $return) => $return->lines)->groupBy('sale_line_id')->map(fn ($lines) => (int) $lines->sum('quantity'));
        $mayRefund = $this->may('sales.void');
        $delivery = DeliveryNote::query()->where('sale_id', $sale->id)->exists();
        $prescription = $sale->prescription_id === null ? null : Prescription::query()->find($sale->prescription_id, ['id', 'number', 'patient_name']);
        $serials = ItemSerial::query()->where('sale_id', $sale->id)->orderBy('serial')->get(['item_id', 'serial'])->groupBy('item_id');

        return [
            'id' => $sale->id,
            'number' => $sale->number,
            'status' => $sale->status,
            'sold_at' => $sale->sold_at->toIso8601String(),
            'sold_by' => $sale->seller->name,
            'customer_name' => $sale->customer_name,
            'customer_phone' => $sale->customer_phone,
            'prescription' => $prescription === null ? null : ['id' => $prescription->id, 'number' => $prescription->number, 'patient_name' => $prescription->patient_name],
            'currency' => $sale->currency,
            'total_minor' => $sale->total_minor,
            'discount_minor' => $sale->discount_minor,
            'payment_method' => $sale->payment_method->value,
            'payment_method_label' => $sale->payment_method->label(),
            'payment_reference' => $sale->payment_reference,
            'amount_tendered_minor' => $sale->amount_tendered_minor,
            'change_minor' => $sale->change_minor,
            'voided_at' => $sale->voided_at?->toIso8601String(),
            'voided_by' => $sale->voider?->name,
            'void_reason' => $sale->void_reason,
            // The receipt: one line per medicine (not per batch); by the pack, so many packs.
            'receipt_lines' => $sale->lines->groupBy(fn (SaleLine $line) => $line->item_id.':'.$line->pack_name)->map(fn ($lines) => [
                'name' => $lines->first()->item->name,
                'pack' => $lines->first()->pack_name === null ? null : "{$lines->first()->pack_name} of {$lines->first()->units_per_pack}",
                'quantity' => intdiv((int) $lines->sum('quantity'), $lines->first()->units_per_pack),
                'unit_price_minor' => $lines->first()->pack_price_minor ?? $lines->first()->unit_price_minor,
                'discount_minor' => (int) $lines->sum('discount_minor'),
                'line_total_minor' => (int) $lines->sum('line_total_minor'),
            ])->values(),
            // What a return counts in: units, batch by batch.
            'lines' => $sale->lines->map(fn (SaleLine $line) => [
                'id' => $line->id,
                'name' => $line->item->name,
                'batch_number' => $line->batch->batch_number,
                'expires_on' => $line->batch->expires_on?->toDateString(),
                'quantity' => $line->quantity,
                'returned' => $returned->get($line->id, 0),
                'line_total_minor' => $line->line_total_minor,
                // A return names the serial of each unit back: one of these.
                'tracks_serials' => $line->item->tracks_serials,
                'serials' => $line->item->tracks_serials ? $serials->get($line->item_id, collect())->pluck('serial')->values() : [],
            ])->values(),
            'returns' => $sale->returns->map(fn (SaleReturn $return) => [
                'id' => $return->id,
                'number' => $return->number,
                'returned_at' => $return->returned_at->toIso8601String(),
                'reason' => $return->reason,
                'total_minor' => $return->total_minor,
            ])->values(),
            // A sale partly returned is finished by returning the rest.
            'can_void' => $mayRefund && $sale->status === Sale::COMPLETED && $sale->returns->isEmpty() && ! $sale->onAccount() && ! $delivery,
            'can_return' => $mayRefund && $sale->status === Sale::COMPLETED
                && $sale->lines->contains(fn (SaleLine $line) => $line->quantity > $returned->get($line->id, 0)),
        ];
    }

    /**
     * Sales of other branches, and of another industry's counter, look unknown.
     */
    private function ensureHere(Sale $sale): void
    {
        abort_unless($sale->branch_id === $this->branch->id() && $sale->application === $this->industry->code(), 404);
    }

    /**
     * Someone else's sale is for those who may see the branch's sales.
     */
    private function ensureVisible(Request $request, Sale $sale): void
    {
        $this->ensureHere($sale);
        abort_unless($sale->sold_by === $this->user($request)->id || $this->may('sales.view') || $this->may('sales.void'), 404);
    }
}
