<?php

use App\Models\Customer;
use App\Models\Driver;
use App\Models\Invoice;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Miles\SharedModuleKit\Envelope;

/*
| Logistics: customers' shipments booked, loaded onto trips, carried and
| delivered with proof; trips costed against what they earned; delivered
| shipments invoiced and paid, with Finance told of each step.
*/

const MANAGER = [
    'logistics.shipments.view', 'logistics.shipments.manage', 'logistics.deliveries.update',
    'logistics.fleet.view', 'logistics.fleet.manage', 'logistics.trips.view', 'logistics.trips.manage',
    'logistics.billing.view', 'logistics.billing.manage', 'logistics.billing.void',
];

beforeEach(function () {
    config(['platform-client.modules.finance' => 'https://finance.internal/events']);
    $this->travelTo(CarbonImmutable::parse('2026-10-26 09:00'));
    $this->asTenant('acme', fn () => Workspace::query()->create(['slug' => 'acme', 'display_name' => 'Acme', 'currency' => 'SSP', 'timezone' => 'Africa/Juba']));

    $this->customer = $this->asTenant('acme', fn () => Customer::query()->create(['name' => 'Nile Traders', 'phone' => '0912000000']));
    $this->truck = $this->asTenant('acme', fn () => Vehicle::query()->create(['registration' => 'SSD 123A', 'kind' => 'truck', 'odometer_km' => 10000, 'status' => Vehicle::AVAILABLE]));
    $this->driver = $this->asTenant('acme', fn () => Driver::query()->create(['name' => 'James Lado', 'licence_expires_on' => '2027-06-30']));
});

/**
 * @param  list<string>  $permissions
 */
function signIn(array $permissions = MANAGER): User
{
    $member = test()->actingAsMember('acme', $permissions);
    assert($member instanceof User);

    return $member;
}

/**
 * Books a shipment for the customer as the signed-in member.
 *
 * @param  array<string, mixed>  $details
 */
function book(array $details = []): Shipment
{
    test()->post(test()->workspaceUrl('acme', 'shipments'), [
        'customer_id' => test()->customer->id,
        'pickup_address' => 'Juba warehouse',
        'delivery_address' => 'Nimule market',
        'recipient_name' => 'Mary Akol',
        'goods' => '40 bags of cement',
        'packages' => 40,
        'weight_kg' => '2000',
        'charge' => '1500.00',
        ...$details,
    ])->assertSessionHasNoErrors();

    return test()->asTenant('acme', fn () => Shipment::query()->latest('number')->firstOrFail());
}

function planTrip(): Trip
{
    test()->post(test()->workspaceUrl('acme', 'trips'), [
        'vehicle_id' => test()->truck->id,
        'driver_id' => test()->driver->id,
        'planned_on' => '2026-10-26',
        'route' => 'Juba → Nimule',
    ])->assertSessionHasNoErrors();

    return test()->asTenant('acme', fn () => Trip::query()->latest('number')->firstOrFail());
}

/**
 * @param  list<Shipment>  $shipments
 */
function load(Trip $trip, array $shipments): void
{
    test()->post(test()->workspaceUrl('acme', "trips/{$trip->id}/shipments"), ['shipment_ids' => array_map(fn (Shipment $shipment) => $shipment->id, $shipments)])
        ->assertSessionHasNoErrors();
}

function fresh(Shipment|Trip|Invoice|Vehicle $model): Shipment|Trip|Invoice|Vehicle
{
    return test()->asTenant('acme', fn () => $model->fresh());
}

/**
 * @return list<string>
 */
function financeEvents(): array
{
    return array_map(fn (array $event) => Envelope::fromArray($event)->eventType, test()->platform()->moduleEvents['finance'] ?? []);
}

/**
 * Delivers a shipment through a trip: planned, loaded, dispatched, delivered and completed.
 */
function deliverOnTrip(Shipment $shipment): Trip
{
    $trip = planTrip();
    load($trip, [$shipment]);
    test()->post(test()->workspaceUrl('acme', "trips/{$trip->id}/dispatch"))->assertSessionHasNoErrors();
    test()->post(test()->workspaceUrl('acme', "shipments/{$shipment->id}/deliver"), ['received_by' => 'Mary Akol'])->assertSessionHasNoErrors();
    test()->post(test()->workspaceUrl('acme', "trips/{$trip->id}/complete"))->assertSessionHasNoErrors();

    return $trip;
}

describe('shipments', function () {
    it('books a shipment with a running number and its first tracking step', function () {
        $member = signIn();

        $shipment = book();

        expect($shipment)
            ->number->toBe('SH-00001')
            ->status->toBe(Shipment::BOOKED)
            ->charge_minor->toBe(150000)
            ->booked_by->toBe($member->id);
        expect(book()->number)->toBe('SH-00002');
        expect($this->asTenant('acme', fn () => ShipmentEvent::query()->where('shipment_id', $shipment->id)->sole()))
            ->status->toBe(Shipment::BOOKED)
            ->location->toBe('Juba warehouse');
    });

    it('lists only the workspace\'s own shipments', function () {
        signIn();
        book();
        $globexCustomer = $this->asTenant('globex', fn () => Customer::query()->create(['name' => 'Globex']));
        $this->asTenant('globex', fn () => Shipment::query()->create([
            'number' => 'SH-00001', 'customer_id' => $globexCustomer->id, 'status' => Shipment::BOOKED,
            'pickup_address' => 'A', 'delivery_address' => 'B', 'recipient_name' => 'C', 'goods' => 'D',
            'charge_minor' => 100, 'booked_by' => User::factory()->create()->id,
        ]));

        $this->get($this->workspaceUrl('acme', 'shipments'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('shipments/index')
                ->has('shipments', 1)
                ->where('shipments.0.customer', 'Nile Traders'));
    });

    it('does not take a customer of another workspace', function () {
        signIn();
        $globexCustomer = $this->asTenant('globex', fn () => Customer::query()->create(['name' => 'Globex']));

        $this->post($this->workspaceUrl('acme', 'shipments'), [
            'customer_id' => $globexCustomer->id, 'pickup_address' => 'A', 'delivery_address' => 'B',
            'recipient_name' => 'C', 'goods' => 'D', 'packages' => 1, 'charge' => '10',
        ])->assertSessionHasErrors('customer_id');
    });

    it('needs the view permission to see shipments and the manage permission to book', function () {
        signIn(['logistics.trips.view']);
        $this->get($this->workspaceUrl('acme', 'shipments'))->assertForbidden();

        signIn(['logistics.shipments.view']);
        $this->get($this->workspaceUrl('acme', 'shipments'))->assertOk();
        $this->post($this->workspaceUrl('acme', 'shipments'), [])->assertForbidden();
    });

    it('cancels a booked shipment but not one on a trip', function () {
        signIn();
        $booked = book();
        $loaded = book();
        load(planTrip(), [$loaded]);

        $this->post($this->workspaceUrl('acme', "shipments/{$booked->id}/cancel"), ['reason' => 'Customer changed plans'])->assertSessionHasNoErrors();
        $this->post($this->workspaceUrl('acme', "shipments/{$loaded->id}/cancel"), ['reason' => 'No'])->assertSessionHasErrors('reason');

        expect(fresh($booked))->status->toBe(Shipment::CANCELLED)->cancel_reason->toBe('Customer changed plans');
        expect(fresh($loaded))->status->toBe(Shipment::ASSIGNED);
    });

    it('keeps the details of a shipment on the road', function () {
        signIn();
        $shipment = book();
        $trip = planTrip();
        load($trip, [$shipment]);
        $this->post($this->workspaceUrl('acme', "trips/{$trip->id}/dispatch"));

        $this->put($this->workspaceUrl('acme', "shipments/{$shipment->id}"), [
            'customer_id' => $this->customer->id, 'pickup_address' => 'A', 'delivery_address' => 'B',
            'recipient_name' => 'C', 'goods' => 'D', 'packages' => 1, 'charge' => '1',
        ])->assertStatus(409);
    });
});

describe('trips', function () {
    it('carries shipments from planning to delivery, with tracking and proof', function () {
        signIn();
        $delivered = book();
        $failed = book(['recipient_name' => 'Peter Deng']);
        $trip = planTrip();
        expect($trip)->number->toBe('TR-00001')->status->toBe(Trip::PLANNED);

        load($trip, [$delivered, $failed]);
        expect(fresh($delivered))->status->toBe(Shipment::ASSIGNED)->trip_id->toBe($trip->id);

        $this->post($this->workspaceUrl('acme', "trips/{$trip->id}/dispatch"), ['start_odometer_km' => 10050])->assertSessionHasNoErrors();
        expect(fresh($trip))->status->toBe(Trip::IN_PROGRESS)->start_odometer_km->toBe(10050);
        expect(fresh($delivered))->status->toBe(Shipment::IN_TRANSIT);

        $this->post($this->workspaceUrl('acme', "shipments/{$delivered->id}/track"), ['location' => 'Nimule border post', 'note' => 'Cleared customs'])->assertSessionHasNoErrors();
        $this->post($this->workspaceUrl('acme', "shipments/{$delivered->id}/deliver"), ['received_by' => 'Mary Akol', 'note' => 'All 40 bags'])->assertSessionHasNoErrors();
        $this->post($this->workspaceUrl('acme', "shipments/{$failed->id}/fail"), ['reason' => 'Shop closed'])->assertSessionHasNoErrors();

        $this->post($this->workspaceUrl('acme', "trips/{$trip->id}/complete"), ['end_odometer_km' => 10250])->assertSessionHasNoErrors();

        expect(fresh($trip))->status->toBe(Trip::COMPLETED)->end_odometer_km->toBe(10250);
        expect(fresh($this->truck))->odometer_km->toBe(10250);
        expect(fresh($delivered))
            ->status->toBe(Shipment::DELIVERED)
            ->received_by->toBe('Mary Akol')
            ->delivery_note->toBe('All 40 bags')
            ->delivered_at->not->toBeNull();
        // Not delivered: back to be booked onto another trip.
        expect(fresh($failed))->status->toBe(Shipment::BOOKED)->trip_id->toBeNull();

        $this->get($this->workspaceUrl('acme', "shipments/{$delivered->id}"))
            ->assertInertia(fn (Assert $page) => $page
                ->component('shipments/show')
                ->where('shipment.events.0.status', 'booked')
                ->where('shipment.events.1.status', 'assigned')
                ->where('shipment.events.2.status', 'in_transit')
                ->where('shipment.events.3.location', 'Nimule border post')
                ->where('shipment.events.4.status', 'delivered')
                ->where('shipment.receivedBy', 'Mary Akol'));
    });

    it('costs a trip against what its delivered shipments earned', function () {
        signIn();
        $trip = deliverOnTrip(book());

        $this->post($this->workspaceUrl('acme', "trips/{$trip->id}/costs"), ['category' => 'fuel', 'amount' => '400.00', 'incurred_on' => '2026-10-26', 'description' => '200 litres'])->assertSessionHasNoErrors();
        $this->post($this->workspaceUrl('acme', "trips/{$trip->id}/costs"), ['category' => 'tolls', 'amount' => '50', 'incurred_on' => '2026-10-26'])->assertSessionHasNoErrors();

        $this->get($this->workspaceUrl('acme', "trips/{$trip->id}"))
            ->assertInertia(fn (Assert $page) => $page
                ->component('trips/show')
                ->where('trip.revenueMinor', 150000)
                ->where('trip.costMinor', 45000)
                ->where('trip.marginMinor', 105000)
                ->has('trip.costs', 2));
    });

    it('will not complete a trip with shipments still in transit', function () {
        signIn();
        $shipment = book();
        $trip = planTrip();
        load($trip, [$shipment]);
        $this->post($this->workspaceUrl('acme', "trips/{$trip->id}/dispatch"));

        $this->post($this->workspaceUrl('acme', "trips/{$trip->id}/complete"))->assertSessionHasErrors('end_odometer_km');

        expect(fresh($trip))->status->toBe(Trip::IN_PROGRESS);
    });

    it('does not dispatch an empty trip, a vehicle in maintenance or a busy vehicle', function () {
        signIn();
        $trip = planTrip();
        $this->post($this->workspaceUrl('acme', "trips/{$trip->id}/dispatch"))->assertSessionHasErrors('start_odometer_km');

        load($trip, [book()]);
        $this->asTenant('acme', fn () => $this->truck->forceFill(['status' => Vehicle::IN_MAINTENANCE])->save());
        $this->post($this->workspaceUrl('acme', "trips/{$trip->id}/dispatch"))->assertSessionHasErrors('start_odometer_km');

        $this->asTenant('acme', fn () => $this->truck->forceFill(['status' => Vehicle::AVAILABLE])->save());
        $other = planTrip();
        load($other, [book()]);
        $this->post($this->workspaceUrl('acme', "trips/{$other->id}/dispatch"))->assertSessionHasNoErrors();
        $this->post($this->workspaceUrl('acme', "trips/{$trip->id}/dispatch"))->assertSessionHasErrors('start_odometer_km');

        expect(fresh($trip))->status->toBe(Trip::PLANNED);
    });

    it('does not plan a trip for a driver whose licence has expired', function () {
        signIn();
        $this->asTenant('acme', fn () => $this->driver->forceFill(['licence_expires_on' => '2026-10-01'])->save());

        $this->post($this->workspaceUrl('acme', 'trips'), ['vehicle_id' => $this->truck->id, 'driver_id' => $this->driver->id, 'planned_on' => '2026-10-26'])
            ->assertSessionHasErrors('vehicle_id');

        expect($this->asTenant('acme', fn () => Trip::query()->count()))->toBe(0);
    });

    it('books the shipments of a cancelled trip again', function () {
        signIn();
        $shipment = book();
        $trip = planTrip();
        load($trip, [$shipment]);

        $this->post($this->workspaceUrl('acme', "trips/{$trip->id}/cancel"), ['reason' => 'Truck needed elsewhere'])->assertSessionHasNoErrors();

        expect(fresh($trip))->status->toBe(Trip::CANCELLED);
        expect(fresh($shipment))->status->toBe(Shipment::BOOKED)->trip_id->toBeNull();
    });

    it('takes a shipment off a planned trip', function () {
        signIn();
        $shipment = book();
        $trip = planTrip();
        load($trip, [$shipment]);

        $this->delete($this->workspaceUrl('acme', "trips/{$trip->id}/shipments/{$shipment->id}"))->assertSessionHasNoErrors();

        expect(fresh($shipment))->status->toBe(Shipment::BOOKED)->trip_id->toBeNull();
    });

    it('lets drivers record deliveries but not plan trips or book shipments', function () {
        signIn();
        $shipment = book();
        $trip = planTrip();
        load($trip, [$shipment]);
        $this->post($this->workspaceUrl('acme', "trips/{$trip->id}/dispatch"));

        signIn(['logistics.shipments.view', 'logistics.deliveries.update', 'logistics.trips.view']);
        $this->post($this->workspaceUrl('acme', 'trips'), [])->assertForbidden();
        $this->post($this->workspaceUrl('acme', 'shipments'), [])->assertForbidden();
        $this->post($this->workspaceUrl('acme', "shipments/{$shipment->id}/deliver"), ['received_by' => 'Mary Akol'])->assertSessionHasNoErrors();

        expect(fresh($shipment))->status->toBe(Shipment::DELIVERED);
    });
});

describe('fleet', function () {
    it('keeps registrations unique within the workspace', function () {
        signIn();

        $this->post($this->workspaceUrl('acme', 'vehicles'), ['registration' => 'ssd 123a', 'kind' => 'van', 'status' => 'available'])
            ->assertSessionHasErrors('registration');
        $this->post($this->workspaceUrl('acme', 'vehicles'), ['registration' => 'SSD 999B', 'kind' => 'van', 'status' => 'available', 'capacity_kg' => 1500])
            ->assertSessionHasNoErrors();
    });

    it('records maintenance and moves the odometer on', function () {
        signIn();

        $this->post($this->workspaceUrl('acme', "vehicles/{$this->truck->id}/maintenance"), [
            'performed_on' => '2026-10-25', 'description' => 'Oil and filters', 'garage' => 'Juba Motors',
            'odometer_km' => 10500, 'cost' => '120.50',
        ])->assertSessionHasNoErrors();

        expect(fresh($this->truck))->odometer_km->toBe(10500);
        $this->get($this->workspaceUrl('acme', "vehicles/{$this->truck->id}"))
            ->assertInertia(fn (Assert $page) => $page
                ->component('vehicles/show')
                ->where('maintenanceCostMinor', 12050)
                ->where('maintenance.0.garage', 'Juba Motors'));
    });
});

describe('billing', function () {
    it('invoices delivered shipments and tells Finance of the invoice and its payments', function () {
        signIn();
        $shipment = book();
        deliverOnTrip($shipment);

        $this->post($this->workspaceUrl('acme', 'invoices'), ['customer_id' => $this->customer->id, 'shipment_ids' => [$shipment->id], 'due_on' => '2026-11-25'])
            ->assertSessionHasNoErrors();
        $invoice = $this->asTenant('acme', fn () => Invoice::query()->sole());
        expect($invoice)
            ->number->toBe('INV-00001')
            ->total_minor->toBe(150000)
            ->currency->toBe('SSP')
            ->status->toBe(Invoice::ISSUED);
        expect(fresh($shipment))->invoice_id->toBe($invoice->id);

        $this->post($this->workspaceUrl('acme', "invoices/{$invoice->id}/payments"), ['amount' => '1000', 'method' => 'bank_transfer', 'reference' => 'TRX-1', 'paid_on' => '2026-10-26'])->assertSessionHasNoErrors();
        expect(fresh($invoice))->status->toBe(Invoice::ISSUED)->paid_minor->toBe(100000);
        $this->post($this->workspaceUrl('acme', "invoices/{$invoice->id}/payments"), ['amount' => '600', 'method' => 'cash', 'paid_on' => '2026-10-26'])->assertSessionHasErrors('amount');
        $this->post($this->workspaceUrl('acme', "invoices/{$invoice->id}/payments"), ['amount' => '500', 'method' => 'cash', 'paid_on' => '2026-10-26'])->assertSessionHasNoErrors();
        expect(fresh($invoice))->status->toBe(Invoice::PAID)->paid_minor->toBe(150000);

        expect(financeEvents())->toBe(['finance.invoice.issued', 'finance.payment.received', 'finance.payment.received']);
        $issued = Envelope::fromArray($this->platform()->moduleEvents['finance'][0]);
        expect($issued->payload)
            ->invoice_number->toBe('INV-00001')
            ->customer_reference->toBe('Nile Traders')
            ->total_minor->toBe(150000)
            ->due_on->toBe('2026-11-25');
        expect($issued->payload['lines'][0])->amount_minor->toBe(150000)->quantity->toBe(1);
        expect(Envelope::fromArray($this->platform()->moduleEvents['finance'][1])->payload)
            ->payment_reference->toBe('RCT-00001')
            ->invoice_number->toBe('INV-00001')
            ->method->toBe('bank_transfer')
            ->processor_reference->toBe('TRX-1');
    });

    it('invoices each delivered shipment once, and only the customer\'s own', function () {
        signIn();
        $delivered = book();
        deliverOnTrip($delivered);
        $notDelivered = book();
        $other = $this->asTenant('acme', fn () => Customer::query()->create(['name' => 'Other']));

        $this->post($this->workspaceUrl('acme', 'invoices'), ['customer_id' => $this->customer->id, 'shipment_ids' => [$notDelivered->id]])->assertSessionHasErrors('shipment_ids');
        $this->post($this->workspaceUrl('acme', 'invoices'), ['customer_id' => $other->id, 'shipment_ids' => [$delivered->id]])->assertSessionHasErrors('shipment_ids');
        $this->post($this->workspaceUrl('acme', 'invoices'), ['customer_id' => $this->customer->id, 'shipment_ids' => [$delivered->id]])->assertSessionHasNoErrors();
        $this->post($this->workspaceUrl('acme', 'invoices'), ['customer_id' => $this->customer->id, 'shipment_ids' => [$delivered->id]])->assertSessionHasErrors('shipment_ids');

        expect($this->asTenant('acme', fn () => Invoice::query()->count()))->toBe(1);
    });

    it('voids an unpaid invoice, freeing its shipments, but not a paid one', function () {
        signIn();
        $first = book();
        deliverOnTrip($first);
        $second = book();
        deliverOnTrip($second);
        $this->post($this->workspaceUrl('acme', 'invoices'), ['customer_id' => $this->customer->id, 'shipment_ids' => [$first->id]]);
        $this->post($this->workspaceUrl('acme', 'invoices'), ['customer_id' => $this->customer->id, 'shipment_ids' => [$second->id]]);
        [$unpaid, $paid] = $this->asTenant('acme', fn () => Invoice::query()->orderBy('number')->get()->all());
        $this->post($this->workspaceUrl('acme', "invoices/{$paid->id}/payments"), ['amount' => '100', 'method' => 'cash', 'paid_on' => '2026-10-26']);

        $this->post($this->workspaceUrl('acme', "invoices/{$unpaid->id}/void"), ['reason' => 'Wrong customer'])->assertSessionHasNoErrors();
        $this->post($this->workspaceUrl('acme', "invoices/{$paid->id}/void"), ['reason' => 'No'])->assertSessionHasErrors('reason');

        expect(fresh($unpaid))->status->toBe(Invoice::VOIDED)->void_reason->toBe('Wrong customer');
        expect(fresh($first))->invoice_id->toBeNull();
        expect(fresh($paid))->status->toBe(Invoice::ISSUED);
        expect(financeEvents())->toContain('finance.invoice.voided');
    });

    it('needs the void permission to void', function () {
        signIn();
        $shipment = book();
        deliverOnTrip($shipment);
        $this->post($this->workspaceUrl('acme', 'invoices'), ['customer_id' => $this->customer->id, 'shipment_ids' => [$shipment->id]]);
        $invoice = $this->asTenant('acme', fn () => Invoice::query()->sole());

        signIn(['logistics.billing.view', 'logistics.billing.manage']);
        $this->post($this->workspaceUrl('acme', "invoices/{$invoice->id}/void"), ['reason' => 'Mine'])->assertForbidden();
    });
});

it('shows each member only the figures they may see on the home page', function () {
    signIn(['logistics.shipments.view']);
    bookDirectly();

    $this->get($this->workspaceUrl('acme'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('home')
            ->where('shipments.waiting', 1)
            ->where('billing', null)
            ->where('fleet', null)
            ->where('currency', 'SSP'));
});

/**
 * A booked shipment, without signing in as someone else.
 */
function bookDirectly(): void
{
    test()->asTenant('acme', fn () => Shipment::query()->create([
        'number' => 'SH-00099', 'customer_id' => test()->customer->id, 'status' => Shipment::BOOKED,
        'pickup_address' => 'A', 'delivery_address' => 'B', 'recipient_name' => 'C', 'goods' => 'D',
        'charge_minor' => 100, 'booked_by' => User::factory()->create()->id,
    ]));
}
