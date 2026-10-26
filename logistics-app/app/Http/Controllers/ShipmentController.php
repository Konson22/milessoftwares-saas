<?php

namespace App\Http\Controllers;

use App\Actions\Shipments;
use App\Models\Customer;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\Trip;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Tenancy\CurrentTenant;

/**
 * Shipments: booking, the details while not on the road, and where each
 * one stands. Tracking updates and proof of delivery are in
 * DeliveryController.
 */
class ShipmentController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->string('search')->toString();
        $status = $request->string('status')->toString();
        $customerId = $request->string('customer')->toString();

        $shipments = Shipment::query()
            ->search($search)
            ->when(in_array($status, Shipment::STATUSES, true), fn ($query) => $query->where('status', $status))
            // Open work by default.
            ->when($status === '', fn ($query) => $query->whereIn('status', [Shipment::BOOKED, Shipment::ASSIGNED, Shipment::IN_TRANSIT, Shipment::FAILED]))
            ->when($customerId !== '', fn ($query) => $query->where('customer_id', $customerId))
            ->with(['customer:id,name', 'trip:id,number'])
            ->latest()
            ->limit(300)
            ->get();

        return Inertia::render('shipments/index', [
            'shipments' => $shipments->map(fn (Shipment $shipment) => [
                'id' => $shipment->id,
                'number' => $shipment->number,
                'customer' => $shipment->customer->name,
                'recipient' => $shipment->recipient_name,
                'pickupAddress' => $shipment->pickup_address,
                'deliveryAddress' => $shipment->delivery_address,
                'status' => $shipment->status,
                'trip' => $shipment->trip?->number,
                'chargeMinor' => $shipment->charge_minor,
                'bookedAt' => $shipment->created_at->toIso8601String(),
            ]),
            'filters' => ['search' => $search, 'status' => $status, 'customer' => $customerId],
            'customers' => $this->customers(),
            'canBook' => $this->can($request, 'logistics.shipments.manage'),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('shipments/form', [
            'shipment' => null,
            'customers' => $this->customers(),
            'customerId' => $request->string('customer')->toString() ?: null,
        ]);
    }

    public function store(Request $request, Shipments $shipments): RedirectResponse
    {
        $shipment = $shipments->book($this->details($request), $this->member($request));

        return to_route('shipments.show', $shipment)->with('success', "Shipment {$shipment->number} booked.");
    }

    public function show(Request $request, Shipment $shipment): Response
    {
        $shipment->load(['customer:id,name,phone', 'trip:id,number,status,vehicle_id,driver_id', 'trip.vehicle:id,registration', 'trip.driver:id,name,phone', 'invoice:id,number,status', 'booker:id,name', 'events.recorder:id,name']);
        $canPlan = $this->can($request, 'logistics.trips.manage');

        return Inertia::render('shipments/show', [
            'shipment' => [
                ...$this->summary($shipment),
                'customer' => ['id' => $shipment->customer->id, 'name' => $shipment->customer->name, 'phone' => $shipment->customer->phone],
                'bookedBy' => $shipment->booker->name,
                'bookedAt' => $shipment->created_at->toIso8601String(),
                'trip' => $shipment->trip === null ? null : [
                    'id' => $shipment->trip->id,
                    'number' => $shipment->trip->number,
                    'status' => $shipment->trip->status,
                    'vehicle' => $shipment->trip->vehicle->registration,
                    'driver' => $shipment->trip->driver->name,
                    'driverPhone' => $shipment->trip->driver->phone,
                ],
                'invoice' => $shipment->invoice === null ? null : ['id' => $shipment->invoice->id, 'number' => $shipment->invoice->number, 'status' => $shipment->invoice->status],
                'deliveredAt' => $shipment->delivered_at?->toIso8601String(),
                'receivedBy' => $shipment->received_by,
                'deliveryNote' => $shipment->delivery_note,
                'cancelReason' => $shipment->cancel_reason,
                'events' => $shipment->events->map(fn (ShipmentEvent $event) => [
                    'id' => $event->id,
                    'status' => $event->status,
                    'location' => $event->location,
                    'note' => $event->note,
                    'recordedBy' => $event->recorder->name,
                    'occurredAt' => $event->occurred_at->toIso8601String(),
                ]),
            ],
            // Trips a booked shipment can be loaded onto from here.
            'trips' => $canPlan && $shipment->status === Shipment::BOOKED
                ? Trip::query()->whereIn('status', [Trip::PLANNED, Trip::IN_PROGRESS])->with('vehicle:id,registration')->orderBy('planned_on')->get()
                    ->map(fn (Trip $trip) => ['id' => $trip->id, 'label' => "{$trip->number} · {$trip->vehicle->registration} · {$trip->planned_on->toDateString()}".($trip->route === null ? '' : " · {$trip->route}")])
                : [],
            'can' => [
                'manage' => $this->can($request, 'logistics.shipments.manage'),
                'deliver' => $this->can($request, 'logistics.deliveries.update'),
                'plan' => $canPlan,
            ],
        ]);
    }

    public function edit(Shipment $shipment): Response|RedirectResponse
    {
        if (! $shipment->editable()) {
            return to_route('shipments.show', $shipment);
        }

        return Inertia::render('shipments/form', [
            'shipment' => [
                ...$this->summary($shipment),
                'charge' => Money::toMajor($shipment->charge_minor),
            ],
            'customers' => $this->customers(),
            'customerId' => $shipment->customer_id,
        ]);
    }

    public function update(Request $request, Shipment $shipment): RedirectResponse
    {
        abort_unless($shipment->editable(), 409, 'This shipment is already on its way.');

        $shipment->fill($this->details($request))->save();

        return to_route('shipments.show', $shipment)->with('success', "Shipment {$shipment->number} saved.");
    }

    public function cancel(Request $request, Shipment $shipment, Shipments $shipments): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $shipments->cancel($shipment, $this->member($request), $validated['reason']);

        return to_route('shipments.show', $shipment)->with('success', "Shipment {$shipment->number} cancelled.");
    }

    /**
     * @return array<string, mixed>
     */
    private function details(Request $request): array
    {
        $validated = $request->validate([
            'customer_id' => ['required', 'uuid', Rule::exists(Customer::class, 'id')->where('organization_id', app(CurrentTenant::class)->id())],
            'pickup_address' => ['required', 'string', 'max:255'],
            'delivery_address' => ['required', 'string', 'max:255'],
            'recipient_name' => ['required', 'string', 'max:255'],
            'recipient_phone' => ['nullable', 'string', 'max:32'],
            'goods' => ['required', 'string', 'max:255'],
            'packages' => ['required', 'integer', 'min:1', 'max:65000'],
            'weight_kg' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'charge' => ['required', 'string', Money::rule()],
            'pickup_on' => ['nullable', 'date'],
        ]);

        return [
            'customer_id' => $validated['customer_id'],
            'pickup_address' => trim($validated['pickup_address']),
            'delivery_address' => trim($validated['delivery_address']),
            'recipient_name' => trim($validated['recipient_name']),
            'recipient_phone' => $this->text($validated['recipient_phone'] ?? null),
            'goods' => trim($validated['goods']),
            'packages' => (int) $validated['packages'],
            'weight_kg' => $validated['weight_kg'] ?? null,
            'charge_minor' => Money::toMinor($validated['charge']),
            'pickup_on' => $validated['pickup_on'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Shipment $shipment): array
    {
        return [
            'id' => $shipment->id,
            'number' => $shipment->number,
            'customerId' => $shipment->customer_id,
            'status' => $shipment->status,
            'pickupAddress' => $shipment->pickup_address,
            'deliveryAddress' => $shipment->delivery_address,
            'recipientName' => $shipment->recipient_name,
            'recipientPhone' => $shipment->recipient_phone,
            'goods' => $shipment->goods,
            'packages' => $shipment->packages,
            'weightKg' => $shipment->weight_kg === null ? null : (float) $shipment->weight_kg,
            'chargeMinor' => $shipment->charge_minor,
            'pickupOn' => $shipment->pickup_on?->toDateString(),
            'editable' => $shipment->editable(),
        ];
    }

    /**
     * Active customers, to choose from.
     *
     * @return list<array{id: string, name: string}>
     */
    private function customers(): array
    {
        return array_values(Customer::query()->where('active', true)->orderBy('name')->get(['id', 'name'])
            ->map(fn (Customer $customer) => ['id' => $customer->id, 'name' => $customer->name])
            ->all());
    }
}
