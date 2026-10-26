<?php

namespace App\Http\Controllers;

use App\Enums\VehicleKind;
use App\Models\MaintenanceRecord;
use App\Models\Trip;
use App\Models\Vehicle;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Tenancy\CurrentTenant;

/**
 * The fleet: vehicles, their trips and maintenance.
 */
class VehicleController extends Controller
{
    public function index(Request $request): Response
    {
        $vehicles = Vehicle::query()->orderByRaw("case status when 'retired' then 1 else 0 end")->orderBy('registration')->get();
        $onTrip = Trip::query()->where('status', Trip::IN_PROGRESS)->pluck('number', 'vehicle_id');

        return Inertia::render('vehicles/index', [
            'vehicles' => $vehicles->map(fn (Vehicle $vehicle) => [
                ...$this->summary($vehicle),
                'onTrip' => $onTrip->get($vehicle->id),
            ]),
            'canManage' => $this->can($request, 'logistics.fleet.manage'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('vehicles/form', ['vehicle' => null, 'kinds' => VehicleKind::options()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $vehicle = new Vehicle;
        $this->save($request, $vehicle);

        return to_route('vehicles.show', $vehicle)->with('success', "{$vehicle->registration} added to the fleet.");
    }

    public function show(Request $request, Vehicle $vehicle): Response
    {
        $trips = $vehicle->trips()->with('driver:id,name')->latest('planned_on')->limit(20)->get();
        $maintenance = $vehicle->maintenance()->with('recorder:id,name')->limit(50)->get();

        return Inertia::render('vehicles/show', [
            'vehicle' => $this->summary($vehicle),
            'trips' => $trips->map(fn (Trip $trip) => [
                'id' => $trip->id,
                'number' => $trip->number,
                'plannedOn' => $trip->planned_on->toDateString(),
                'route' => $trip->route,
                'driver' => $trip->driver->name,
                'status' => $trip->status,
                'distanceKm' => $trip->distanceKm(),
            ]),
            'maintenance' => $maintenance->map(fn (MaintenanceRecord $record) => [
                'id' => $record->id,
                'performedOn' => $record->performed_on->toDateString(),
                'description' => $record->description,
                'garage' => $record->garage,
                'odometerKm' => $record->odometer_km,
                'costMinor' => $record->cost_minor,
                'recordedBy' => $record->recorder->name,
            ]),
            'maintenanceCostMinor' => (int) $vehicle->maintenance()->sum('cost_minor'),
            'canManage' => $this->can($request, 'logistics.fleet.manage'),
        ]);
    }

    public function edit(Vehicle $vehicle): Response
    {
        return Inertia::render('vehicles/form', ['vehicle' => $this->summary($vehicle), 'kinds' => VehicleKind::options()]);
    }

    public function update(Request $request, Vehicle $vehicle): RedirectResponse
    {
        $this->save($request, $vehicle);

        return to_route('vehicles.show', $vehicle)->with('success', "{$vehicle->registration} saved.");
    }

    /**
     * Servicing or a repair, and what it cost.
     */
    public function maintain(Request $request, Vehicle $vehicle): RedirectResponse
    {
        $validated = $request->validate([
            'performed_on' => ['required', 'date', 'before_or_equal:today'],
            'description' => ['required', 'string', 'max:255'],
            'garage' => ['nullable', 'string', 'max:255'],
            'odometer_km' => ['nullable', 'integer', 'min:0', 'max:9999999'],
            'cost' => ['required', 'string', Money::rule()],
        ]);

        MaintenanceRecord::query()->create([
            'vehicle_id' => $vehicle->id,
            'performed_on' => $validated['performed_on'],
            'description' => trim($validated['description']),
            'garage' => $this->text($validated['garage'] ?? null),
            'odometer_km' => $validated['odometer_km'] ?? null,
            'cost_minor' => Money::toMinor($validated['cost']),
            'recorded_by' => $this->member($request)->id,
        ]);
        if (isset($validated['odometer_km']) && $validated['odometer_km'] > $vehicle->odometer_km) {
            $vehicle->forceFill(['odometer_km' => (int) $validated['odometer_km']])->save();
        }

        return to_route('vehicles.show', $vehicle)->with('success', "Maintenance of {$vehicle->registration} recorded.");
    }

    private function save(Request $request, Vehicle $vehicle): void
    {
        // One spelling per plate, so "ssd 123a" is SSD 123A when checking it is unique.
        $request->merge(['registration' => mb_strtoupper($request->string('registration')->trim()->toString())]);

        $validated = $request->validate([
            'registration' => [
                'required', 'string', 'max:32',
                Rule::unique('vehicles', 'registration')
                    ->where('organization_id', $vehicle->organization_id ?? app(CurrentTenant::class)->id())
                    ->ignore($vehicle->id),
            ],
            'kind' => ['required', Rule::enum(VehicleKind::class)],
            'description' => ['nullable', 'string', 'max:255'],
            'capacity_kg' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'odometer_km' => ['nullable', 'integer', 'min:0', 'max:9999999'],
            'status' => ['required', Rule::in(Vehicle::STATUSES)],
        ]);

        $vehicle->fill([
            'registration' => $validated['registration'],
            'kind' => $validated['kind'],
            'description' => $this->text($validated['description'] ?? null),
            'capacity_kg' => $validated['capacity_kg'] ?? null,
            'odometer_km' => (int) ($validated['odometer_km'] ?? $vehicle->odometer_km ?? 0),
            'status' => $validated['status'],
        ])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Vehicle $vehicle): array
    {
        return [
            'id' => $vehicle->id,
            'registration' => $vehicle->registration,
            'kind' => $vehicle->kind->value,
            'kindLabel' => $vehicle->kind->label(),
            'description' => $vehicle->description,
            'capacityKg' => $vehicle->capacity_kg,
            'odometerKm' => $vehicle->odometer_km,
            'status' => $vehicle->status,
        ];
    }
}
