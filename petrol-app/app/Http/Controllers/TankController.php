<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\WorksInBranch;
use App\Models\FuelProduct;
use App\Models\FuelReceipt;
use App\Models\Shift;
use App\Models\Station;
use App\Models\StockMovement;
use App\Models\Tank;
use App\Models\TankChart;
use App\Models\TankMeasurement;
use App\Petrol\Calibration;
use App\Petrol\Measurements;
use App\Petrol\Permissions;
use App\Petrol\TankLedger;
use App\Support\Money;
use App\Support\Volume;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TankController extends Controller
{
    use WorksInBranch;

    public function index(): Response
    {
        $station = Station::query()->where('branch_id', $this->branchId())->first();
        $percent = $station->low_stock_percent ?? 15;

        return Inertia::render('tanks/index', [
            'tanks' => Tank::query()->with('product')->where('branch_id', $this->branchId())->orderBy('code')->get()->map(fn (Tank $tank) => $this->row($tank, $percent)),
            'products' => FuelProduct::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
            'canManage' => $this->canHere(Permissions::TANKS_MANAGE),
            'canAdjust' => $this->canHere(Permissions::STOCK_ADJUST),
        ]);
    }

    public function show(Tank $tank): Response
    {
        $this->ensureHere($tank->branch_id);
        $station = Station::query()->where('branch_id', $tank->branch_id)->first();

        return Inertia::render('tanks/show', [
            'tank' => $this->row($tank, $station->low_stock_percent ?? 15),
            'movements' => StockMovement::query()->where('tank_id', $tank->id)->latest('occurred_at')->limit(50)->get()->map(fn (StockMovement $movement) => [
                'id' => $movement->id,
                'kind' => $movement->kind,
                'litres' => Volume::toLitres($movement->quantity_milli),
                'at' => $movement->occurred_at->toIso8601String(),
                'notes' => $movement->notes,
            ]),
            'measurements' => TankMeasurement::query()->where('tank_id', $tank->id)->latest('measured_at')->limit(20)->get()->map(fn (TankMeasurement $measurement) => [
                'id' => $measurement->id,
                'method' => $measurement->method,
                'measured' => Volume::toLitres($measurement->measured_milli),
                'book' => Volume::toLitres($measurement->book_milli),
                'variance' => Volume::toLitres($measurement->variance_milli),
                'water' => $measurement->water_mm,
                'status' => $measurement->status,
                'at' => $measurement->measured_at->toIso8601String(),
                'notes' => $measurement->notes,
            ]),
            'chart' => ($chart = TankChart::query()->with('points')->where('tank_id', $tank->id)->where('status', TankChart::ACTIVE)->first()) === null ? null : [
                'reference' => $chart->reference,
                'version' => $chart->version,
                'points' => $chart->points->sortBy('depth_mm')->map(fn ($point) => [
                    'depth' => $point->depth_mm,
                    'litres' => Volume::toLitres($point->volume_milli),
                ])->values(),
            ],
            'receipts' => FuelReceipt::query()->where('tank_id', $tank->id)->latest()->limit(10)->get()->map(fn (FuelReceipt $receipt) => [
                'number' => $receipt->number,
                'status' => $receipt->status,
                'declared' => Volume::toLitres($receipt->declared_milli),
                'accepted' => $receipt->accepted_milli === null ? null : Volume::toLitres($receipt->accepted_milli),
                'reconciliation' => $receipt->reconciliation,
            ]),
            'canAdjust' => $this->canHere(Permissions::STOCK_ADJUST),
            'canMeasure' => $this->canHere(Permissions::MEASUREMENTS_RECORD) || $this->canHere(Permissions::STOCK_ADJUST),
            'canVerify' => $this->canHere(Permissions::MEASUREMENTS_VERIFY),
            'canChart' => $this->canHere(Permissions::CHARTS_MANAGE),
            'waterAlert' => (bool) $tank->water_alert,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'fuel_product_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:32'],
            'capacity' => ['required', Volume::rule()],
            'safe_fill' => ['nullable', Volume::rule()],
            'low_stock' => ['nullable', Volume::rule()],
            'reorder' => ['nullable', Volume::rule()],
            'tank_type' => ['nullable', Rule::in([Tank::UNDERGROUND, Tank::ABOVEGROUND])],
            'material' => ['nullable', 'string', 'max:64'],
            'status' => ['required', Rule::in([Tank::ACTIVE, Tank::OUT, Tank::MAINTENANCE])],
        ]);

        $product = FuelProduct::query()->whereKey($validated['fuel_product_id'])->where('active', true)->first();
        abort_if($product === null, 422);

        $capacity = Volume::toMilli($validated['capacity']);
        $safe = isset($validated['safe_fill']) && $validated['safe_fill'] !== '' ? Volume::toMilli($validated['safe_fill']) : null;
        $low = isset($validated['low_stock']) && $validated['low_stock'] !== '' ? Volume::toMilli($validated['low_stock']) : null;
        $reorder = isset($validated['reorder']) && $validated['reorder'] !== '' ? Volume::toMilli($validated['reorder']) : null;
        if ($safe !== null && $safe > $capacity) {
            throw ValidationException::withMessages(['safe_fill' => 'The safe fill cannot exceed the tank capacity.']);
        }
        if (($low !== null && $low > $capacity) || ($reorder !== null && $reorder > $capacity) || ($low !== null && $reorder !== null && $reorder < $low)) {
            throw ValidationException::withMessages(['reorder' => 'Minimum stock and the reorder level must sit within the tank, with reorder at or above the minimum.']);
        }

        Tank::query()->create([
            'branch_id' => $this->branchId(),
            'fuel_product_id' => $product->id,
            'name' => trim($validated['name']),
            'code' => strtoupper(trim($validated['code'])),
            'tank_type' => $validated['tank_type'] ?? Tank::UNDERGROUND,
            'material' => $this->text($validated['material'] ?? null),
            'capacity_milli' => $capacity,
            'safe_fill_milli' => $safe,
            'low_stock_milli' => $low,
            'reorder_milli' => $reorder,
            'status' => $validated['status'],
        ]);

        return back()->with('success', 'Tank added.');
    }

    public function opening(Request $request, Tank $tank, TankLedger $ledger): RedirectResponse
    {
        $this->ensureHere($tank->branch_id);
        $validated = $request->validate([
            'litres' => ['required', Volume::rule()],
            'cost' => ['required', Money::rule()],
        ]);

        $ledger->opening($tank, Volume::toMilli($validated['litres']), Money::toMinor($validated['cost']), $this->member($request));

        return back()->with('success', 'Opening stock recorded.');
    }

    public function update(Request $request, Tank $tank): RedirectResponse
    {
        $this->ensureHere($tank->branch_id);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'safe_fill' => ['nullable', Volume::rule()],
            'low_stock' => ['nullable', Volume::rule()],
            'reorder' => ['nullable', Volume::rule()],
            'tank_type' => ['required', Rule::in([Tank::UNDERGROUND, Tank::ABOVEGROUND])],
            'material' => ['nullable', 'string', 'max:64'],
            'measurement_method' => ['required', Rule::in([TankMeasurement::DIPSTICK, TankMeasurement::VOLUME, TankMeasurement::GAUGE])],
            'status' => ['required', Rule::in([Tank::ACTIVE, Tank::OUT, Tank::MAINTENANCE])],
            'fuel_product_id' => ['required', 'uuid'],
        ]);
        if ($validated['fuel_product_id'] !== $tank->fuel_product_id && StockMovement::query()->where('tank_id', $tank->id)->exists()) {
            throw ValidationException::withMessages(['fuel_product_id' => 'This tank already has stock history, so its fuel type stays.']);
        }
        $safe = isset($validated['safe_fill']) && $validated['safe_fill'] !== '' ? Volume::toMilli($validated['safe_fill']) : null;
        if ($safe !== null && $safe > $tank->capacity_milli) {
            throw ValidationException::withMessages(['safe_fill' => 'The safe fill cannot exceed the tank capacity.']);
        }
        $tank->forceFill([
            'name' => trim($validated['name']),
            'fuel_product_id' => $validated['fuel_product_id'],
            'safe_fill_milli' => $safe,
            'low_stock_milli' => isset($validated['low_stock']) && $validated['low_stock'] !== '' ? Volume::toMilli($validated['low_stock']) : null,
            'reorder_milli' => isset($validated['reorder']) && $validated['reorder'] !== '' ? Volume::toMilli($validated['reorder']) : null,
            'tank_type' => $validated['tank_type'],
            'material' => $this->text($validated['material'] ?? null),
            'measurement_method' => $validated['measurement_method'],
            'status' => $validated['status'],
        ])->save();

        return back()->with('success', 'Tank details saved. The stock quantity was not changed.');
    }

    public function chart(Request $request, Tank $tank, Calibration $calibration): RedirectResponse
    {
        $this->ensureHere($tank->branch_id);
        $validated = $request->validate([
            'reference' => ['required', 'string', 'max:64'],
            'effective_from' => ['required', 'date'],
            'provider' => ['nullable', 'string', 'max:255'],
            'document_note' => ['nullable', 'string', 'max:255'],
            'csv' => ['required', 'string', 'max:20000'],
        ]);
        $calibration->publish(
            $tank,
            trim($validated['reference']),
            $validated['effective_from'],
            $this->text($validated['provider'] ?? null),
            $this->text($validated['document_note'] ?? null),
            $calibration->pointsFromCsv($validated['csv']),
            $this->member($request),
        );

        return back()->with('success', 'Calibration chart saved. Earlier dips keep the chart they used.');
    }

    public function measure(Request $request, Tank $tank, Measurements $measurements): RedirectResponse
    {
        $this->ensureHere($tank->branch_id);
        abort_unless($this->canHere(Permissions::MEASUREMENTS_RECORD) || $this->canHere(Permissions::STOCK_ADJUST), 403);
        $validated = $request->validate([
            'method' => ['required', Rule::in([TankMeasurement::DIPSTICK, TankMeasurement::VOLUME, TankMeasurement::GAUGE])],
            'litres' => ['nullable', Volume::rule()],
            'depth_mm' => ['nullable', 'integer', 'min:0'],
            'water_mm' => ['nullable', 'integer', 'min:0'],
            'temperature_c' => ['nullable', 'numeric'],
            'notes' => ['nullable', 'string', 'max:255'],
            'evidence' => ['nullable', 'string', 'max:255'],
        ]);
        $shiftId = Shift::query()->where('branch_id', $tank->branch_id)->where('status', Shift::OPEN)->value('id');
        $measurements->record($tank, [
            'method' => $validated['method'],
            'litres' => $validated['litres'] ?? null,
            'depth_mm' => isset($validated['depth_mm']) ? (int) $validated['depth_mm'] : null,
            'water_mm' => isset($validated['water_mm']) ? (int) $validated['water_mm'] : null,
            'temperature_c' => isset($validated['temperature_c']) ? (string) $validated['temperature_c'] : null,
            'notes' => $this->text($validated['notes'] ?? null),
            'evidence' => $this->text($validated['evidence'] ?? null),
        ], $this->member($request), $shiftId);

        return back()->with('success', 'Measurement recorded. The stock ledger was not changed.');
    }

    public function verifyMeasurement(Request $request, TankMeasurement $measurement, Measurements $measurements): RedirectResponse
    {
        $this->ensureHere($measurement->branch_id);
        $measurements->verify($measurement, $this->member($request));

        return back()->with('success', 'Measurement verified.');
    }

    public function adjust(Request $request, TankMeasurement $measurement, Measurements $measurements): RedirectResponse
    {
        $this->ensureHere($measurement->branch_id);
        $measurements->postAdjustment($measurement, $this->member($request));

        return back()->with('success', 'The verified difference was posted to stock once.');
    }

    public function clearWater(Request $request, Tank $tank, Measurements $measurements): RedirectResponse
    {
        $this->ensureHere($tank->branch_id);
        $validated = $request->validate(['note' => ['required', 'string', 'max:255']]);
        $measurements->clearWater($tank, $this->member($request), trim($validated['note']));

        return back()->with('success', 'Water hold cleared.');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Tank $tank, int $percent): array
    {
        $tank->loadMissing('product');

        return [
            'id' => $tank->id,
            'name' => $tank->name,
            'code' => $tank->code,
            'product' => $tank->product->name,
            'capacity' => Volume::toLitres($tank->capacity_milli),
            'quantity' => Volume::toLitres($tank->quantity_milli),
            'averageCostMinor' => $tank->average_cost_minor,
            'status' => $tank->status,
            'low' => $tank->isLow($percent),
        ];
    }
}
