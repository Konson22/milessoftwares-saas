<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\WorksInBranch;
use App\Models\AttendantStation;
use App\Models\Customer;
use App\Models\FuelPrice;
use App\Models\FuelSale;
use App\Models\Nozzle;
use App\Models\PaymentMethod;
use App\Models\SalePayment;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Station;
use App\Petrol\Permissions;
use App\Petrol\Sales;
use App\Support\Money;
use App\Support\Volume;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SaleController extends Controller
{
    use WorksInBranch;

    public function index(): Response
    {
        return Inertia::render('sales/index', [
            'sales' => FuelSale::query()->with(['product', 'nozzle'])->where('branch_id', $this->branchId())->latest('sold_at')->limit(80)->get()->map(fn (FuelSale $sale) => $this->summary($sale)),
            'canSell' => $this->canHere(Permissions::SALES_CREATE),
        ]);
    }

    public function create(): Response
    {
        $branchId = $this->branchId();
        $shift = Shift::query()->where('branch_id', $branchId)->where('status', Shift::OPEN)->first();

        // Who works each nozzle on the open shift, so the phone offers only them.
        $assigned = $shift === null ? collect() : ShiftAssignment::query()
            ->where('shift_id', $shift->id)
            ->whereNull('ended_at')
            ->where('status', ShiftAssignment::ACTIVE)
            ->get(['nozzle_id', 'attendant_id'])
            ->groupBy('nozzle_id');
        $books = Money::currency();

        return Inertia::render('sales/create', [
            'shift' => $shift === null ? null : ['id' => $shift->id, 'number' => $shift->number],
            'nozzles' => Nozzle::query()->with('product')->where('branch_id', $branchId)->where('status', Nozzle::ACTIVE)->orderBy('code')->get()
                ->map(function (Nozzle $nozzle) use ($branchId, $assigned, $books) {
                    $price = FuelPrice::current($branchId, $nozzle->fuel_product_id);

                    return [
                        'id' => $nozzle->id,
                        'name' => $nozzle->name,
                        'code' => $nozzle->code,
                        'product' => $nozzle->product->name,
                        'priceMinor' => $price?->selling_price_minor,
                        'currency' => $price === null ? $books : ($price->currency ?: $books),
                        'attendantIds' => $assigned->get($nozzle->id, collect())->pluck('attendant_id')->values(),
                    ];
                }),
            'collectionMode' => Station::query()->where('branch_id', $branchId)->value('collection_mode'),
            'attendants' => AttendantStation::query()->where('branch_id', $branchId)->where('active', true)->with('attendant')->get()
                ->filter(fn (AttendantStation $row) => $row->attendant->active)
                ->map(fn (AttendantStation $row) => ['id' => $row->attendant->id, 'name' => $row->attendant->name])->values(),
            'methods' => PaymentMethod::query()->where('branch_id', $branchId)->where('active', true)->orderBy('name')->get(['id', 'name', 'kind']),
            'customers' => Customer::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
            'idempotencyKey' => (string) Str::uuid(),
            'canCollectForOthers' => $this->canHere(Permissions::CASH_MANAGE) || $this->canHere(Permissions::SHIFTS_APPROVE),
        ]);
    }

    public function show(FuelSale $sale): Response
    {
        $this->ensureHere($sale->branch_id);
        $sale->load(['payments', 'product', 'nozzle', 'attendant']);

        return Inertia::render('sales/show', [
            'sale' => [
                ...$this->summary($sale),
                'priceMinor' => $sale->unit_price_minor,
                'costMinor' => $sale->cost_minor,
                'voidReason' => $sale->void_reason,
                'payments' => $sale->payments->map(fn (SalePayment $payment) => [
                    'method' => $payment->kind,
                    'kind' => $payment->kind,
                    'amountMinor' => $payment->amount_minor,
                    'reference' => $payment->reference,
                ]),
            ],
            'canVoid' => $this->canHere(Permissions::SALES_VOID) && $sale->isPosted(),
        ]);
    }

    public function store(Request $request, Sales $sales): RedirectResponse
    {
        $validated = $request->validate([
            'nozzle_id' => ['required', 'uuid'],
            'litres' => ['nullable', Volume::rule()],
            'amount' => ['nullable', Money::anyRule()],
            'attendant_id' => ['required', 'uuid'],
            'collected_by' => ['required', Rule::in(['attendant', 'cashier'])],
            'idempotency_key' => ['nullable', 'uuid'],
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.method_id' => ['required', 'uuid'],
            'payments.*.amount' => ['required', Money::anyRule()],
            'payments.*.reference' => ['nullable', 'string', 'max:128'],
            'payments.*.customer_id' => ['nullable', 'uuid'],
        ]);

        $sale = $sales->record($this->station(), $this->member($request), [
            'nozzle_id' => $validated['nozzle_id'],
            'litres' => $this->text($validated['litres'] ?? null),
            'amount' => $this->text($validated['amount'] ?? null),
            'attendant_id' => $validated['attendant_id'],
            'collected_by' => $validated['collected_by'],
            'idempotency_key' => $this->text($validated['idempotency_key'] ?? null),
            'payments' => array_values(array_map(fn (array $row) => [
                'method_id' => $row['method_id'],
                'amount' => $row['amount'],
                'reference' => $row['reference'] ?? null,
                'customer_id' => $row['customer_id'] ?? null,
            ], $validated['payments'])),
        ], $this->canHere(Permissions::CASH_MANAGE) || $this->canHere(Permissions::SHIFTS_APPROVE));

        return to_route('sales.show', $sale)->with('success', "Sale {$sale->number} recorded.");
    }

    public function void(Request $request, FuelSale $sale, Sales $sales): RedirectResponse
    {
        $this->ensureHere($sale->branch_id);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $sales->void($sale, $this->member($request), $validated['reason']);

        return back()->with('success', "Sale {$sale->number} voided.");
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(FuelSale $sale): array
    {
        $sale->loadMissing(['product', 'nozzle', 'attendant']);

        return [
            'id' => $sale->id,
            'number' => $sale->number,
            'status' => $sale->status,
            'product' => $sale->product->name,
            'nozzle' => $sale->nozzle->code,
            'attendant' => $sale->attendant->name,
            'litres' => Volume::toLitres($sale->quantity_milli),
            'totalMinor' => $sale->total_minor,
            'currency' => $sale->currency ?: Money::currency(),
            'soldAt' => $sale->sold_at->toIso8601String(),
        ];
    }
}
