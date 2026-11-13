<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\WorksInBranch;
use App\Models\FuelPrice;
use App\Models\FuelProduct;
use App\Models\PaymentMethod;
use App\Models\ShiftSchedule;
use App\Models\Station;
use App\Petrol\Currencies;
use App\Petrol\StationSetup;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    use WorksInBranch;

    public function index(Request $request): Response
    {
        $branchId = $this->branchId();
        $station = Station::query()->where('branch_id', $branchId)->first();

        return Inertia::render('settings/index', [
            'section' => $request->string('section')->toString() ?: 'station',
            'station' => $station === null ? null : [
                'code' => $station->code,
                'collectionMode' => $station->collection_mode,
                'lowStockPercent' => $station->low_stock_percent,
                'hours' => $station->hours,
                'notes' => $station->notes,
                'active' => $station->active,
                'main' => $station->is_main,
                'sharedPumps' => $station->shared_pumps,
                'dispenseDuringReceiving' => $station->dispense_during_receiving,
                'waterAlertMm' => $station->water_alert_mm,
                'calibrationIntervalDays' => $station->calibration_interval_days,
                'operations' => [
                    'fuel' => $station->runs('fuel'),
                    'service' => $station->runs('service'),
                    'store' => $station->runs('store'),
                ],
            ],
            'schedules' => ShiftSchedule::query()->where('branch_id', $branchId)->orderBy('starts_at')->get()->map(fn (ShiftSchedule $schedule) => [
                'id' => $schedule->id,
                'name' => $schedule->name,
                'startsAt' => $schedule->starts_at,
                'endsAt' => $schedule->ends_at,
                'crossesMidnight' => $schedule->crossesMidnight(),
                'active' => $schedule->active,
            ]),
            'products' => FuelProduct::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'prices' => FuelPrice::query()->with('product')->where('branch_id', $branchId)->whereNull('effective_to')->get()->map(fn (FuelPrice $price) => [
                'product' => $price->product->name,
                'priceMinor' => $price->selling_price_minor,
                'currency' => $price->currency,
            ]),
            'methods' => PaymentMethod::query()->where('branch_id', $branchId)->orderBy('name')->get()->map(fn (PaymentMethod $method) => [
                'id' => $method->id,
                'name' => $method->name,
                'kind' => $method->kind,
                'active' => $method->active,
            ]),
            'accessUrl' => $request->getSchemeAndHttpHost().'/members',
            'currencies' => app(Currencies::class)->accepted($branchId),
        ]);
    }

    public function save(Request $request, StationSetup $setup): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'collection_mode' => ['required', Rule::in([Station::ATTENDANT, Station::CASHIER, Station::BOTH])],
            'low_stock_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'hours' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:255'],
            'active' => ['boolean'],
            'water_alert_mm' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'calibration_interval_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        $station = $setup->saveStation($this->branchId(), [
            'code' => strtoupper(trim($validated['code'])),
            'collection_mode' => $validated['collection_mode'],
            'low_stock_percent' => (int) $validated['low_stock_percent'],
            'hours' => $this->text($validated['hours'] ?? null),
            'notes' => $this->text($validated['notes'] ?? null),
            'active' => (bool) ($validated['active'] ?? true),
            ...($request->has('shared_pumps') ? ['shared_pumps' => $request->boolean('shared_pumps')] : []),
            ...($request->has('dispense_during_receiving') ? ['dispense_during_receiving' => $request->boolean('dispense_during_receiving')] : []),
            ...($request->has('water_alert_mm') ? ['water_alert_mm' => isset($validated['water_alert_mm']) ? (int) $validated['water_alert_mm'] : null] : []),
            ...($request->has('calibration_interval_days') ? ['calibration_interval_days' => isset($validated['calibration_interval_days']) ? (int) $validated['calibration_interval_days'] : null] : []),
            ...($request->boolean('operations_form') ? ['operations' => [
                'fuel' => $request->boolean('op_fuel'),
                'service' => $request->boolean('op_service'),
                'store' => $request->boolean('op_store'),
            ]] : []),
        ], $this->member($request));

        return back()->with('success', "Station {$station->code} saved.");
    }

    public function schedule(Request $request, StationSetup $setup): RedirectResponse
    {
        $validated = $this->scheduleInput($request);
        $setup->saveSchedule($this->branchId(), $validated['name'], $validated['starts_at'], $validated['ends_at'], (bool) ($validated['active'] ?? true), null);

        return back()->with('success', 'Shift schedule added.');
    }

    public function updateSchedule(Request $request, ShiftSchedule $schedule, StationSetup $setup): RedirectResponse
    {
        $this->ensureHere($schedule->branch_id);
        $validated = $this->scheduleInput($request);
        $setup->saveSchedule($schedule->branch_id, $validated['name'], $validated['starts_at'], $validated['ends_at'], (bool) ($validated['active'] ?? true), $schedule);

        return back()->with('success', 'Shift schedule saved.');
    }

    public function price(Request $request, StationSetup $setup): RedirectResponse
    {
        $this->station();
        $validated = $request->validate([
            'fuel_product_id' => ['required', 'uuid'],
            'selling_price' => ['required', Money::anyRule()],
            'currency' => ['nullable', Rule::in(app(Currencies::class)->acceptedCodes($this->branchId()))],
        ]);
        $product = FuelProduct::query()->whereKey($validated['fuel_product_id'])->where('active', true)->firstOrFail();
        $setup->setPrice($this->branchId(), $product, Money::input($validated['selling_price'], $validated['currency'] ?? null, 'selling_price'), $this->member($request), $validated['currency'] ?? null);

        return back()->with('success', "Price for {$product->name} saved.");
    }

    public function payment(Request $request, PaymentMethod $method): RedirectResponse
    {
        $this->ensureHere($method->branch_id);
        $validated = $request->validate(['active' => ['required', 'boolean']]);
        $method->forceFill(['active' => $validated['active']])->save();

        return back()->with('success', $method->name.' '.($method->active ? 'enabled' : 'disabled').'.');
    }

    /**
     * @return array{name: string, starts_at: string, ends_at: string, active?: bool}
     */
    private function scheduleInput(Request $request): array
    {
        /** @var array{name: string, starts_at: string, ends_at: string, active?: bool} $validated */
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'starts_at' => ['required', 'string', 'max:5'],
            'ends_at' => ['required', 'string', 'max:5'],
            'active' => ['boolean'],
        ]);

        return $validated;
    }
}
