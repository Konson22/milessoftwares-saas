<?php

namespace Database\Seeders;

use App\Actions\Invoices;
use App\Actions\Shipments;
use App\Actions\Trips;
use App\Enums\PaymentMethod;
use App\Enums\TripCostCategory;
use App\Enums\VehicleKind;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\MaintenanceRecord;
use App\Models\Shipment;
use App\Models\TripCost;
use App\Models\User;
use App\Models\Vehicle;
use App\Platform\SetUpWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Miles\PlatformClient\Api\Organization;
use Miles\PlatformClient\Tenancy\CurrentTenant;
use RuntimeException;

/**
 * The water tanker fleet of the demo business central's DemoBusinessSeeder
 * made (DEMO_BUSINESS_FILE), entered through the app's services with the clock
 * set back, so three months of deliveries carry their own dates. A load of
 * 1,000 litres weighs 1,000 kg. Amounts are in cents.
 */
class DemoBusinessSeeder extends Seeder
{
    /** @var array<string, User> */
    private array $team = [];

    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('DemoBusinessSeeder only runs with APP_ENV=local.');
        }

        $data = json_decode((string) file_get_contents((string) getenv('DEMO_BUSINESS_FILE')), true, flags: JSON_THROW_ON_ERROR);
        $organization = Organization::fromArray($data['organization']);

        app(CurrentTenant::class)->run($organization, function () use ($data, $organization) {
            try {
                DB::transaction(function () use ($data, $organization) {
                    app(SetUpWorkspace::class)->provision($organization);

                    foreach ($data['users'] as $key => $user) {
                        $member = new User;
                        $member->forceFill($user)->save();
                        $this->team[$key] = $member;
                    }

                    $this->seedFleet();
                    $this->holdBackEvents();
                });
            } finally {
                $this->clock(null);
            }
        });
    }

    private function seedFleet(): void
    {
        $fleet = $this->team['fleet_officer'];

        $this->clock('2026-08-01 08:00');
        $tankers = [];
        foreach ([
            ['SSD 201 WT', '18,000 litre tanker', 18000, 84200],
            ['SSD 202 WT', '18,000 litre tanker', 18000, 61350],
            ['SSD 203 WT', '10,000 litre tanker', 10000, 47900],
        ] as [$registration, $description, $capacity, $odometer]) {
            $tankers[] = Vehicle::query()->create(['registration' => $registration, 'kind' => VehicleKind::Truck, 'description' => $description, 'capacity_kg' => $capacity, 'odometer_km' => $odometer, 'status' => Vehicle::AVAILABLE]);
        }
        Vehicle::query()->create(['registration' => 'SSD 310 PK', 'kind' => VehicleKind::Pickup, 'description' => 'Repair crew pickup', 'capacity_kg' => 1000, 'odometer_km' => 38100, 'status' => Vehicle::AVAILABLE]);
        $old = Vehicle::query()->create(['registration' => 'SSD 118 WT', 'kind' => VehicleKind::Truck, 'description' => '8,000 litre tanker, gearbox out', 'capacity_kg' => 8000, 'odometer_km' => 212400, 'status' => Vehicle::IN_MAINTENANCE]);

        $drivers = [];
        foreach ([
            [$this->team['driver']->name, '+211 921 000 201', 'SS-DL-40112', '2028-06-30'],
            ['Lino Wani', '+211 921 000 202', 'SS-DL-40587', '2027-09-30'],
            ['Akim Lokiru', '+211 921 000 203', 'SS-DL-41203', '2027-02-28'],
            ['Bol Madut', '+211 921 000 204', 'SS-DL-38841', '2026-12-15'],
        ] as [$name, $phone, $licence, $expires]) {
            $drivers[] = Driver::query()->create(['name' => $name, 'phone' => $phone, 'licence_number' => $licence, 'licence_expires_on' => $expires, 'active' => true]);
        }

        $customers = [];
        foreach ([
            ['Juba Water Vendors Association', '+211 922 000 301', 'accounts@jwva.test', 'Konyokonyo, Juba', 'Kiosk 4, Konyokonyo', 'Mama Keji'],
            ['Munuki Primary Schools Board', '+211 922 000 302', 'board@munuki-schools.test', 'Munuki, Juba', 'Munuki Block B school', 'Mr Yugu'],
            ['Gudele Community Clinic', '+211 922 000 303', 'admin@gudele-clinic.test', 'Gudele, Juba', 'Clinic tank, Gudele Block 9', 'Sister Amer'],
            ['Kator Hotel Group', '+211 922 000 304', 'stores@kator-hotels.test', 'Kator, Juba', 'Hotel yard, Kator', 'Mr Ladu'],
            ['Sherikat Construction', '+211 922 000 305', 'site@sherikat-build.test', 'Sherikat, Juba', 'Site office, Sherikat', 'Eng. Lomoro'],
        ] as [$name, $phone, $email, $address, $site, $contact]) {
            $customer = Customer::query()->create(['name' => $name, 'phone' => $phone, 'email' => $email, 'address' => $address, 'active' => true]);
            $customers[] = ['customer' => $customer, 'site' => $site, 'contact' => $contact, 'phone' => $phone];
        }

        $this->seedDeliveries($tankers, $drivers, $customers);

        // Keeping the fleet on the road.
        foreach ([
            ['2026-08-18', $tankers[0], 'Full service and new tyres', 'Imatong Pumps and Motors', 86000, 240000],
            ['2026-09-09', $tankers[1], 'Tank valve replaced', 'Depot workshop', 63000, 45000],
            ['2026-10-06', $tankers[2], 'Brake pads and oil change', 'Depot workshop', 49800, 62000],
            ['2026-10-21', $old, 'Gearbox removed, part on order', 'Imatong Pumps and Motors', 212400, 180000],
        ] as [$on, $vehicle, $description, $garage, $odometer, $cost]) {
            MaintenanceRecord::query()->create(['vehicle_id' => $vehicle->id, 'performed_on' => $on, 'description' => $description, 'garage' => $garage, 'odometer_km' => $odometer, 'cost_minor' => $cost, 'recorded_by' => $fleet->id]);
        }

        $this->clock(null);
    }

    /**
     * @param  list<Vehicle>  $tankers
     * @param  list<Driver>  $drivers
     * @param  list<array{customer: Customer, site: string, contact: string, phone: string}>  $customers
     */
    private function seedDeliveries(array $tankers, array $drivers, array $customers): void
    {
        $shipments = app(Shipments::class);
        $trips = app(Trips::class);
        $dispatcher = $this->team['dispatcher'];
        $driver = $this->team['driver'];
        $delivered = [];

        // A round each week from August: every tanker takes two or three loads.
        for ($week = 0; $week < 13; $week++) {
            $on = CarbonImmutable::parse('2026-08-03')->addWeeks($week);

            foreach ($tankers as $index => $tanker) {
                $this->clock($on->addDays($index)->setTime(7, 30)->toDateTimeString());
                $capacity = (int) $tanker->capacity_kg;
                $loads = $capacity >= 18000 ? 3 : 2;
                $booked = [];

                for ($load = 0; $load < $loads; $load++) {
                    $to = $customers[($week + $index + $load) % count($customers)];
                    $litres = intdiv($capacity, $loads);
                    $booked[] = $shipments->book([
                        'customer_id' => $to['customer']->id,
                        'pickup_address' => 'Juba treatment plant filling point',
                        'delivery_address' => $to['site'],
                        'recipient_name' => $to['contact'],
                        'recipient_phone' => $to['phone'],
                        'goods' => number_format($litres).' litres treated water',
                        'packages' => 1,
                        'weight_kg' => $litres,
                        'charge_minor' => $litres * 2,
                        'pickup_on' => $on->addDays($index)->toDateString(),
                    ], $dispatcher);
                }

                $trip = $trips->plan($tanker, $drivers[$index], $on->addDays($index), 'Filling point, then '.implode(', then ', array_map(fn (Shipment $s) => $s->delivery_address, $booked)), $dispatcher);
                $trips->load($trip, array_map(fn (Shipment $s) => $s->id, $booked), $dispatcher);
                $trips->dispatch($trip, null, $dispatcher);

                $this->clock($on->addDays($index)->setTime(10, 45)->toDateTimeString());
                $shipments->track($booked[0], $driver, 'Juba bridge road', 'Left the filling point full.');

                $this->clock($on->addDays($index)->setTime(15, 20)->toDateTimeString());
                foreach ($booked as $number => $shipment) {
                    if ($week === 6 && $index === 0 && $number === 1) {
                        $shipments->fail($shipment, $driver, 'Site gate closed, no one to receive.');

                        continue;
                    }
                    $shipments->deliver($shipment, $driver, $shipment->recipient_name, null);
                    $delivered[$shipment->customer_id][$on->format('Y-m')][] = $shipment->id;
                }

                $start = (int) $trip->fresh()->start_odometer_km;
                $trips->complete($trip, $start + 46 + ($week * 3) % 17, $dispatcher);
                TripCost::query()->create(['trip_id' => $trip->id, 'category' => TripCostCategory::Fuel, 'description' => 'Diesel', 'amount_minor' => 5500 + ($week % 4) * 400, 'incurred_on' => $on->addDays($index)->toDateString(), 'recorded_by' => $dispatcher->id]);
                if ($week % 3 === 0) {
                    TripCost::query()->create(['trip_id' => $trip->id, 'category' => TripCostCategory::Allowance, 'description' => 'Driver allowance', 'amount_minor' => 1500, 'incurred_on' => $on->addDays($index)->toDateString(), 'recorded_by' => $dispatcher->id]);
                }
            }
        }

        $this->seedInvoices($customers, $delivered);

        // Today: one tanker out, one planned, loads waiting and one called off.
        $this->clock('2026-11-04 08:10');
        $today = CarbonImmutable::parse('2026-11-04');
        $booking = fn (array $to, int $litres, string $on) => $shipments->book([
            'customer_id' => $to['customer']->id,
            'pickup_address' => 'Juba treatment plant filling point',
            'delivery_address' => $to['site'],
            'recipient_name' => $to['contact'],
            'recipient_phone' => $to['phone'],
            'goods' => number_format($litres).' litres treated water',
            'packages' => 1,
            'weight_kg' => $litres,
            'charge_minor' => $litres * 2,
            'pickup_on' => $on,
        ], $dispatcher);

        $out = [$booking($customers[0], 9000, '2026-11-04'), $booking($customers[2], 9000, '2026-11-04')];
        $onRoad = $trips->plan($tankers[0], $drivers[0], $today, 'Filling point, then Konyokonyo, then Gudele', $dispatcher);
        $trips->load($onRoad, array_map(fn (Shipment $s) => $s->id, $out), $dispatcher);
        $trips->dispatch($onRoad, null, $dispatcher);
        $shipments->track($out[0], $driver, 'Custom market junction', 'Slow traffic, about an hour out.');
        $shipments->deliver($out[0], $driver, $out[0]->recipient_name, 'Tank filled.');

        $planned = $trips->plan($tankers[1], $drivers[1], $today->addDay(), 'Filling point, then Munuki', $dispatcher);
        $trips->load($planned, [$booking($customers[1], 18000, '2026-11-05')->id], $dispatcher);

        $booking($customers[3], 10000, '2026-11-06');
        $booking($customers[4], 18000, '2026-11-09');
        $shipments->cancel($booking($customers[4], 6000, '2026-11-04'), $dispatcher, 'Site closed for the week.');

        $this->clock(null);
    }

    /**
     * August and September invoiced and mostly paid, October just issued.
     *
     * @param  list<array{customer: Customer, site: string, contact: string, phone: string}>  $customers
     * @param  array<string, array<string, list<string>>>  $delivered
     */
    private function seedInvoices(array $customers, array $delivered): void
    {
        $invoices = app(Invoices::class);
        $accountant = $this->team['accountant'];

        foreach (['2026-08', '2026-09', '2026-10'] as $month) {
            $issued = CarbonImmutable::parse($month.'-01')->endOfMonth()->startOfDay();
            $this->clock($issued->setTime(16, 0)->toDateTimeString());

            foreach ($customers as $index => $to) {
                $ids = $delivered[$to['customer']->id][$month] ?? [];
                if ($ids === []) {
                    continue;
                }
                $invoice = $invoices->issue($to['customer'], $ids, $issued->addDays(30), $accountant);

                if ($month === '2026-10') {
                    continue;
                }
                $paidOn = $issued->addDays(14 + $index * 3);
                $this->clock($paidOn->setTime(11, 0)->toDateTimeString());
                $method = $index % 2 === 0 ? PaymentMethod::BankTransfer : PaymentMethod::MobileMoney;
                $amount = ($month === '2026-09' && $index === 3) ? intdiv($invoice->total_minor, 2) : $invoice->total_minor;
                if ($month === '2026-09' && $index === 4) {
                    continue;
                }
                $invoices->pay($invoice, $amount, $method, 'RCPT-'.substr($month, 5).$index, $paidOn, $accountant);
                $this->clock($issued->setTime(16, 0)->toDateTimeString());
            }
        }
    }

    /**
     * Finance has its own demo books: what these entries would tell it (and
     * HR) is not sent, or it would all land in Finance's first open month.
     */
    private function holdBackEvents(): void
    {
        DB::table('platform_outbox')->where('status', 'pending')->delete();
    }

    private function clock(?string $at): void
    {
        Carbon::setTestNow($at);
        CarbonImmutable::setTestNow($at);
    }
}
