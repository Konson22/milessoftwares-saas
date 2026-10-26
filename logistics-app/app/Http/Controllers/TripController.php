<?php

namespace App\Http\Controllers;

use App\Actions\Trips;
use App\Enums\TripCostCategory;
use App\Models\Driver;
use App\Models\Shipment;
use App\Models\Trip;
use App\Models\TripCost;
use App\Models\Vehicle;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Trips: planning, loading shipments, dispatch and completion, and what
 * each one cost against what it earned.
 */
class TripController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString();

        $trips = Trip::query()
            ->when(in_array($status, Trip::STATUSES, true), fn ($query) => $query->where('status', $status))
            ->when($status === '', fn ($query) => $query->whereIn('status', [Trip::PLANNED, Trip::IN_PROGRESS]))
            ->with(['vehicle:id,registration', 'driver:id,name'])
            ->withCount('shipments')
            ->withSum('costs as cost_minor', 'amount_minor')
            ->withSum(['shipments as revenue_minor' => fn ($query) => $query->where('status', Shipment::DELIVERED)], 'charge_minor')
            ->latest('planned_on')
            ->latest()
            ->limit(200)
            ->get();

        return Inertia::render('trips/index', [
            'trips' => $trips->map(fn (Trip $trip) => [
                'id' => $trip->id,
                'number' => $trip->number,
                'plannedOn' => $trip->planned_on->toDateString(),
                'route' => $trip->route,
                'vehicle' => $trip->vehicle->registration,
                'driver' => $trip->driver->name,
                'status' => $trip->status,
                'shipments' => (int) $trip->getAttribute('shipments_count'),
                'revenueMinor' => (int) $trip->getAttribute('revenue_minor'),
                'costMinor' => (int) $trip->getAttribute('cost_minor'),
            ]),
            'filters' => ['status' => $status],
            'canPlan' => $this->can($request, 'logistics.trips.manage'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('trips/create', [
            'vehicles' => Vehicle::query()->where('status', Vehicle::AVAILABLE)->orderBy('registration')->get()
                ->map(fn (Vehicle $vehicle) => ['id' => $vehicle->id, 'label' => $vehicle->label()]),
            'drivers' => Driver::query()->where('active', true)->orderBy('name')->get()
                ->map(fn (Driver $driver) => ['id' => $driver->id, 'label' => $driver->name.($driver->licenceExpired() ? ' (licence expired)' : '')]),
            'today' => today()->toDateString(),
        ]);
    }

    public function store(Request $request, Trips $trips): RedirectResponse
    {
        $validated = $request->validate([
            'vehicle_id' => ['required', 'uuid'],
            'driver_id' => ['required', 'uuid'],
            'planned_on' => ['required', 'date'],
            'route' => ['nullable', 'string', 'max:255'],
        ]);

        $vehicle = Vehicle::query()->whereKey($validated['vehicle_id'])->first() ?? throw ValidationException::withMessages(['vehicle_id' => 'Choose a vehicle of the fleet.']);
        $driver = Driver::query()->whereKey($validated['driver_id'])->first() ?? throw ValidationException::withMessages(['driver_id' => 'Choose a driver.']);

        $trip = $trips->plan($vehicle, $driver, CarbonImmutable::parse($validated['planned_on']), $validated['route'] ?? null, $this->member($request));

        return to_route('trips.show', $trip)->with('success', "Trip {$trip->number} planned: load its shipments.");
    }

    public function show(Request $request, Trip $trip): Response
    {
        $trip->load(['vehicle', 'driver', 'shipments.customer:id,name', 'costs.recorder:id,name']);
        $canPlan = $this->can($request, 'logistics.trips.manage');
        $revenue = (int) $trip->shipments->where('status', Shipment::DELIVERED)->sum('charge_minor');
        $cost = (int) $trip->costs->sum('amount_minor');

        return Inertia::render('trips/show', [
            'trip' => [
                'id' => $trip->id,
                'number' => $trip->number,
                'status' => $trip->status,
                'plannedOn' => $trip->planned_on->toDateString(),
                'route' => $trip->route,
                'vehicle' => ['id' => $trip->vehicle->id, 'label' => $trip->vehicle->label(), 'odometerKm' => $trip->vehicle->odometer_km],
                'driver' => ['name' => $trip->driver->name, 'phone' => $trip->driver->phone],
                'startOdometerKm' => $trip->start_odometer_km,
                'endOdometerKm' => $trip->end_odometer_km,
                'distanceKm' => $trip->distanceKm(),
                'dispatchedAt' => $trip->dispatched_at?->toIso8601String(),
                'completedAt' => $trip->completed_at?->toIso8601String(),
                'cancelReason' => $trip->cancel_reason,
                'shipments' => $trip->shipments->map(fn (Shipment $shipment) => [
                    'id' => $shipment->id,
                    'number' => $shipment->number,
                    'customer' => $shipment->customer->name,
                    'recipient' => $shipment->recipient_name,
                    'deliveryAddress' => $shipment->delivery_address,
                    'goods' => $shipment->goods,
                    'status' => $shipment->status,
                    'chargeMinor' => $shipment->charge_minor,
                ]),
                'costs' => $trip->costs->map(fn (TripCost $cost) => [
                    'id' => $cost->id,
                    'category' => $cost->category->label(),
                    'description' => $cost->description,
                    'amountMinor' => $cost->amount_minor,
                    'incurredOn' => $cost->incurred_on->toDateString(),
                    'recordedBy' => $cost->recorder->name,
                ]),
                'revenueMinor' => $revenue,
                'costMinor' => $cost,
                'marginMinor' => $revenue - $cost,
            ],
            // Booked shipments waiting for a trip.
            'loadable' => $canPlan && in_array($trip->status, [Trip::PLANNED, Trip::IN_PROGRESS], true)
                ? Shipment::query()->where('status', Shipment::BOOKED)->with('customer:id,name')->orderBy('pickup_on')->orderBy('number')->limit(200)->get()
                    ->map(fn (Shipment $shipment) => [
                        'id' => $shipment->id,
                        'number' => $shipment->number,
                        'customer' => $shipment->customer->name,
                        'deliveryAddress' => $shipment->delivery_address,
                        'goods' => $shipment->goods,
                        'weightKg' => $shipment->weight_kg === null ? null : (float) $shipment->weight_kg,
                    ])
                : [],
            'costCategories' => TripCostCategory::options(),
            'today' => today()->toDateString(),
            'can' => [
                'plan' => $canPlan,
                'deliver' => $this->can($request, 'logistics.deliveries.update'),
            ],
        ]);
    }

    public function load(Request $request, Trip $trip, Trips $trips): RedirectResponse
    {
        $validated = $request->validate([
            'shipment_ids' => ['required', 'array', 'min:1', 'max:200'],
            'shipment_ids.*' => ['uuid'],
        ]);

        $count = $trips->load($trip, array_values($validated['shipment_ids']), $this->member($request));

        return back()->with('success', $count === 1 ? "1 shipment loaded onto {$trip->number}." : "{$count} shipments loaded onto {$trip->number}.");
    }

    public function unload(Request $request, Trip $trip, Shipment $shipment, Trips $trips): RedirectResponse
    {
        $trips->unload($trip, $shipment, $this->member($request));

        return back()->with('success', "{$shipment->number} taken off {$trip->number}.");
    }

    public function dispatch(Request $request, Trip $trip, Trips $trips): RedirectResponse
    {
        $validated = $request->validate(['start_odometer_km' => ['nullable', 'integer', 'min:0', 'max:9999999']]);

        $trips->dispatch($trip, isset($validated['start_odometer_km']) ? (int) $validated['start_odometer_km'] : null, $this->member($request));

        return back()->with('success', "Trip {$trip->number} is on the road.");
    }

    public function complete(Request $request, Trip $trip, Trips $trips): RedirectResponse
    {
        $validated = $request->validate(['end_odometer_km' => ['nullable', 'integer', 'min:0', 'max:9999999']]);

        $trips->complete($trip, isset($validated['end_odometer_km']) ? (int) $validated['end_odometer_km'] : null, $this->member($request));

        return back()->with('success', "Trip {$trip->number} completed.");
    }

    public function cancel(Request $request, Trip $trip, Trips $trips): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $trips->cancel($trip, $validated['reason'], $this->member($request));

        return back()->with('success', "Trip {$trip->number} cancelled.");
    }

    public function addCost(Request $request, Trip $trip): RedirectResponse
    {
        $validated = $request->validate([
            'category' => ['required', Rule::enum(TripCostCategory::class)],
            'description' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'string', Money::rule()],
            'incurred_on' => ['required', 'date', 'before_or_equal:today'],
        ]);
        $this->ensureCostsOpen($trip, 'amount');

        $trip->costs()->create([
            'category' => $validated['category'],
            'description' => $this->text($validated['description'] ?? null),
            'amount_minor' => Money::toMinor($validated['amount']),
            'incurred_on' => $validated['incurred_on'],
            'recorded_by' => $this->member($request)->id,
        ]);

        return back()->with('success', "Cost added to {$trip->number}.");
    }

    public function removeCost(Trip $trip, TripCost $cost): RedirectResponse
    {
        abort_unless($cost->trip_id === $trip->id, 404);
        $this->ensureCostsOpen($trip, 'cost');

        $cost->delete();

        return back()->with('success', "Cost removed from {$trip->number}.");
    }

    /**
     * @throws ValidationException
     */
    private function ensureCostsOpen(Trip $trip, string $field): void
    {
        if ($trip->status === Trip::CANCELLED) {
            throw ValidationException::withMessages([$field => "{$trip->number} is cancelled."]);
        }
    }
}
