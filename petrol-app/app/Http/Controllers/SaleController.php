<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\WorksInBranch;
use App\Models\AttendantStation;
use App\Models\Customer;
use App\Models\FuelPrice;
use App\Models\FuelProduct;
use App\Models\FuelSale;
use App\Models\Nozzle;
use App\Models\PaymentMethod;
use App\Models\SalePayment;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Station;
use App\Petrol\Permissions;
use App\Petrol\Sales;
use App\Support\BusinessDate;
use App\Support\Money;
use App\Support\Volume;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SaleController extends Controller
{
    use WorksInBranch;

    /**
     * The station's sales ledger: one day by default, filtered by grade,
     * attendant, payment, status or receipt number. Totals count posted
     * sales only; voided ones are listed but never added up.
     */
    public function index(Request $request): Response
    {
        $branchId = $this->branchId();
        $today = BusinessDate::today();
        $from = $this->day($request->string('from')->toString(), $today);
        $to = $this->day($request->string('to')->toString(), $from);
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 92) {
            $from = CarbonImmutable::parse($to)->subDays(92)->toDateString();
        }
        [$start, $end] = BusinessDate::range($from, $to);

        $filters = [
            'product' => $request->string('product')->toString(),
            'attendant' => $request->string('attendant')->toString(),
            'method' => $request->string('method')->toString(),
            'status' => $request->string('status')->toString(),
            'q' => trim($request->string('q')->toString()),
        ];

        $filtered = FuelSale::query()
            ->where('fuel_sales.branch_id', $branchId)
            ->whereBetween('fuel_sales.sold_at', [$start, $end])
            ->when(Str::isUuid($filters['product']), fn ($query) => $query->where('fuel_sales.fuel_product_id', $filters['product']))
            ->when(Str::isUuid($filters['attendant']), fn ($query) => $query->where('fuel_sales.attendant_id', $filters['attendant']))
            ->when(in_array($filters['method'], [PaymentMethod::CASH, PaymentMethod::MOBILE, PaymentMethod::BANK, PaymentMethod::CREDIT], true), fn ($query) => $query
                ->whereHas('payments', fn ($payments) => $payments->where('kind', $filters['method'])))
            ->when(in_array($filters['status'], [FuelSale::POSTED, FuelSale::VOIDED], true), fn ($query) => $query->where('fuel_sales.status', $filters['status']))
            ->when($filters['q'] !== '', fn ($query) => $query->whereRaw('upper(fuel_sales.number) like ?', ['%'.strtoupper(str_replace(['%', '_'], '', $filters['q'])).'%']));

        $posted = (clone $filtered)->where('fuel_sales.status', FuelSale::POSTED);
        $totals = (clone $posted)
            ->selectRaw('count(*) as sales_count, coalesce(sum(coalesce(reporting_total_minor, total_minor)), 0) as total_minor, coalesce(sum(quantity_milli), 0) as milli, coalesce(sum(cost_minor), 0) as cost_minor')
            ->first();
        $count = (int) $totals?->getAttribute('sales_count');
        $total = (int) $totals?->getAttribute('total_minor');

        $payments = SalePayment::query()
            ->whereIn('fuel_sale_id', (clone $posted)->select('fuel_sales.id'))
            ->selectRaw('kind, sum(amount_minor) as total_minor')
            ->groupBy('kind')
            ->pluck('total_minor', 'kind');

        $grades = (clone $posted)
            ->join('fuel_products', 'fuel_products.id', '=', 'fuel_sales.fuel_product_id')
            ->selectRaw('fuel_products.name as name, sum(coalesce(fuel_sales.reporting_total_minor, fuel_sales.total_minor)) as total_minor, sum(fuel_sales.quantity_milli) as milli, count(*) as sales_count')
            ->groupBy('fuel_products.name')
            ->orderBy('fuel_products.name')
            ->get()
            ->map(fn ($row) => [
                'name' => (string) $row->getAttribute('name'),
                'totalMinor' => (int) $row->getAttribute('total_minor'),
                'litres' => Volume::toLitres((int) $row->getAttribute('milli')),
                'count' => (int) $row->getAttribute('sales_count'),
            ]);

        $customers = Customer::query()->pluck('name', 'id');
        $page = (clone $filtered)
            ->with(['product', 'nozzle', 'attendant', 'payments'])
            ->latest('fuel_sales.sold_at')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (FuelSale $sale) => [
                ...$this->summary($sale),
                'priceMinor' => $sale->unit_price_minor,
                'payments' => $sale->payments->map(fn (SalePayment $payment) => [
                    'kind' => $payment->kind,
                    'amountMinor' => $payment->amount_minor,
                    'reference' => $payment->reference,
                    'customer' => $payment->customer_id === null ? null : $customers->get($payment->customer_id),
                ])->values(),
            ]);

        return Inertia::render('sales/index', [
            'sales' => $page,
            'from' => $from,
            'to' => $to,
            'today' => $today,
            'filters' => $filters,
            'summary' => [
                'count' => $count,
                'totalMinor' => $total,
                'litres' => Volume::toLitres((int) $totals?->getAttribute('milli')),
                'profitMinor' => $total - (int) $totals?->getAttribute('cost_minor'),
                'averageMinor' => $count > 0 ? intdiv($total, $count) : 0,
                'voided' => (clone $filtered)->where('fuel_sales.status', FuelSale::VOIDED)->count(),
                'payments' => collect([PaymentMethod::CASH, PaymentMethod::MOBILE, PaymentMethod::BANK, PaymentMethod::CREDIT])
                    ->map(fn (string $kind) => ['kind' => $kind, 'totalMinor' => (int) ($payments[$kind] ?? 0)])
                    ->values(),
                'grades' => $grades,
                'hours' => $this->hours($posted, $from === $to),
            ],
            'options' => [
                'products' => FuelProduct::query()->orderBy('name')->get(['id', 'name']),
                'attendants' => AttendantStation::query()->where('branch_id', $branchId)->with('attendant')->get()
                    ->map(fn (AttendantStation $row) => ['id' => $row->attendant->id, 'name' => $row->attendant->name])
                    ->sortBy('name')->values(),
            ],
            'canSell' => $this->canHere(Permissions::SALES_CREATE),
        ]);
    }

    /**
     * Sales by local hour for one day, or by local day for a range.
     *
     * @param  Builder<FuelSale>  $posted
     * @return list<array{label: string, totalMinor: int, count: int}>
     */
    private function hours(Builder $posted, bool $oneDay): array
    {
        $timezone = BusinessDate::timezone();
        if (preg_match('#^[A-Za-z0-9_+\-/]{1,64}$#', $timezone) !== 1) {
            $timezone = 'UTC';
        }
        if (DB::connection()->getDriverName() === 'pgsql') {
            $bucket = $oneDay
                ? "to_char((fuel_sales.sold_at at time zone 'UTC') at time zone ?, 'HH24')"
                : "to_char((fuel_sales.sold_at at time zone 'UTC') at time zone ?, 'YYYY-MM-DD')";
            $bindings = [$timezone];
        } else {
            $bucket = $oneDay ? "strftime('%H', fuel_sales.sold_at)" : "strftime('%Y-%m-%d', fuel_sales.sold_at)";
            $bindings = [];
        }
        $rows = (clone $posted)
            ->selectRaw($bucket.' as bucket, sum(coalesce(reporting_total_minor, total_minor)) as total_minor, count(*) as sales_count', $bindings)
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        $buckets = [];
        foreach ($rows as $row) {
            $buckets[] = [
                'label' => (string) $row->getAttribute('bucket'),
                'totalMinor' => (int) $row->getAttribute('total_minor'),
                'count' => (int) $row->getAttribute('sales_count'),
            ];
        }

        return $buckets;
    }

    private function day(string $value, string $fallback): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && strtotime($value) !== false ? $value : $fallback;
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
