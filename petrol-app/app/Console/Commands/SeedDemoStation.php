<?php

namespace App\Console\Commands;

use App\Models\Attendant;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FuelPrice;
use App\Models\FuelProduct;
use App\Models\FuelReceipt;
use App\Models\Nozzle;
use App\Models\PaymentMethod;
use App\Models\Pump;
use App\Models\PurchaseOrder;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Models\Station;
use App\Models\Supplier;
use App\Models\Tank;
use App\Models\User;
use App\Models\Workspace;
use App\Petrol\Cashbook;
use App\Petrol\Credit;
use App\Petrol\Equipment;
use App\Petrol\Purchasing;
use App\Petrol\Sales;
use App\Petrol\Shifts;
use App\Petrol\StationSetup;
use App\Petrol\TankLedger;
use App\Support\Money;
use App\Support\Volume;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Miles\PlatformClient\Api\Organization;
use Miles\PlatformClient\Tenancy\CurrentTenant;

/**
 * Local demo data for one workspace: a station with three fuel grades, its
 * pumps and tanks, and four weeks of shifts entered through the app's
 * services with the clock set back, so every sale, delivery and payment
 * carries its own date. Finance events are held back, as in the other apps'
 * demo seeders. Runs once per station.
 */
class SeedDemoStation extends Command
{
    protected $signature = 'petrol:demo
        {workspace : The workspace display name or organization id}
        {branch : The central branch id the station belongs to}
        {--days=28 : Days of history before today}
        {--owner= : Email of the member who approves (required outside local)}
        {--force : Run outside APP_ENV=local}';

    protected $description = 'Seed a workspace with a demo fuel station (outside local only with --force and --owner)';

    private const TZ = 'Africa/Juba';

    private User $owner;

    private User $supervisor;

    private Station $station;

    private string $branch;

    /** @var array<string, PaymentMethod> */
    private array $methods = [];

    /** @var array<string, Tank> keyed by product code */
    private array $tanks = [];

    /** @var array<string, FuelProduct> */
    private array $products = [];

    /** @var list<Nozzle> */
    private array $nozzles = [];

    /** @var list<Attendant> */
    private array $attendants = [];

    /** @var list<Customer> */
    private array $customers = [];

    /** @var array<string, Supplier> keyed by product code */
    private array $suppliers = [];

    /** @var array<string, ExpenseCategory> */
    private array $categories = [];

    /** @var array<string, int> purchase cost per litre (minor) by product code */
    private array $cost = [];

    /** @var array<string, PurchaseOrder> open orders by product code */
    private array $ordered = [];

    /** @var array<int, FuelReceipt> receipts still to pay */
    private array $unpaid = [];

    private int $sales = 0;

    /** The real time, read before the clock is set back. */
    private CarbonImmutable $now;

    public function handle(CurrentTenant $tenant): int
    {
        $local = app()->environment('local');
        if (! $local && (! $this->option('force') || ! $this->option('owner'))) {
            $this->components->error('Outside APP_ENV=local, petrol:demo needs --force and --owner=<email>.');

            return self::FAILURE;
        }

        $name = (string) $this->argument('workspace');
        $workspace = Workspace::acrossTenants()
            ->where(fn ($query) => Str::isUuid($name) ? $query->where('organization_id', $name) : $query->where('display_name', $name))
            ->first();
        if ($workspace === null) {
            $this->components->error("No petrol workspace called {$name}. Open Petrol Station in the workspace first.");

            return self::FAILURE;
        }

        $this->branch = (string) $this->argument('branch');
        $organization = Organization::fromArray([
            'id' => $workspace->organization_id,
            'slug' => (string) $workspace->organization_id,
            'display_name' => $workspace->display_name,
            'status' => 'active',
            'default_currency' => $workspace->currency,
            'timezone' => $workspace->timezone,
        ]);

        mt_srand(20261115);

        try {
            $tenant->run($organization, function () {
                if (Station::query()->where('branch_id', $this->branch)->exists()) {
                    throw new \RuntimeException('This branch already has a station; the demo runs once.');
                }

                // Users are shared by every workspace: outside local, name the owner.
                $owner = $this->option('owner');
                $this->owner = $owner
                    ? User::query()->where('email', $owner)->first() ?? throw new \RuntimeException("No user {$owner} in Petrol. Open Petrol Station as that member first.")
                    : User::query()->orderBy('created_at')->firstOrFail();
                // A second member: someone else has to approve the closings she submits.
                $this->supervisor = User::query()->where('email', 'grace.lado@demo.tatook.local')->first()
                    ?? tap(new User, fn (User $user) => $user->forceFill(['id' => (string) Str::uuid7(), 'name' => 'Grace Lado', 'email' => 'grace.lado@demo.tatook.local'])->save());

                $this->now = CarbonImmutable::now(self::TZ);
                $start = $this->now->startOfDay()->subDays((int) $this->option('days'));

                // One transaction per day: a single one around everything turns
                // each service's transaction into a savepoint, and thousands of
                // them slow Postgres to a crawl.
                $this->step(fn () => $this->setUp($start));
                $this->history($start, $this->now->startOfDay());
                $this->step(fn () => $this->today());

                // Delivery jobs are queued after each commit; their rows are gone.
                DB::table('jobs')->where('payload', 'like', '%DeliverOutboxEvent%')->delete();
            });
        } catch (\RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        } finally {
            $this->clock(null);
        }

        $this->components->info("Demo station seeded: {$this->sales} sales.");

        return self::SUCCESS;
    }

    private function setUp(CarbonImmutable $start): void
    {
        $this->clock($start->setTime(5, 0));
        $setup = app(StationSetup::class);

        $this->station = $setup->saveStation($this->branch, [
            'code' => 'JUB-01',
            'collection_mode' => Station::CASHIER,
            'low_stock_percent' => 25,
            'hours' => '06:00 - 22:00',
            'notes' => 'Juba-Nimule road, Gudele junction',
            'active' => true,
            'operations' => ['fuel' => true, 'service' => false, 'store' => false],
            'shared_pumps' => false,
            'dispense_during_receiving' => false,
            'water_alert_mm' => 30,
            'calibration_interval_days' => 180,
        ], $this->owner);

        foreach (PaymentMethod::query()->where('branch_id', $this->branch)->get() as $method) {
            $this->methods[$method->kind] = $method;
        }

        $setup->saveSchedule($this->branch, 'Morning', '06:00', '14:00', true, null);
        $setup->saveSchedule($this->branch, 'Evening', '14:00', '22:00', true, null);

        // Grade, tank litres, opening litres, price, cost.
        $grades = [
            'PMS' => ['Petrol (PMS)', 30000, 17500, 420000, 365000],
            'AGO' => ['Diesel (AGO)', 40000, 26000, 460000, 395000],
            'DPK' => ['Kerosene (DPK)', 10000, 6200, 380000, 320000],
        ];
        $ledger = app(TankLedger::class);
        $n = 1;
        foreach ($grades as $code => [$name, $capacity, $opening, $price, $cost]) {
            $product = $setup->saveProduct($name, $code, true, null, $this->owner);
            $this->products[$code] = $product;
            $this->cost[$code] = $cost;
            $setup->setPrice($this->branch, $product, $price, $this->owner);

            $tank = Tank::query()->create([
                'branch_id' => $this->branch,
                'fuel_product_id' => $product->id,
                'name' => 'Tank '.$n.' '.explode(' ', $name)[0],
                'code' => 'T'.$n++,
                'tank_type' => Tank::UNDERGROUND,
                'material' => 'Steel',
                'capacity_milli' => $capacity * 1000,
                'safe_fill_milli' => (int) ($capacity * 0.95) * 1000,
                'low_stock_milli' => (int) ($capacity * 0.2) * 1000,
                'reorder_milli' => (int) ($capacity * 0.4) * 1000,
                'status' => Tank::ACTIVE,
            ]);
            $ledger->opening($tank, $opening * 1000, $cost, $this->owner);
            $this->tanks[$code] = $tank->fresh();
        }

        $equipment = app(Equipment::class);
        foreach ([
            ['Pump 1', 'P1', ['PMS', 'AGO']],
            ['Pump 2', 'P2', ['PMS', 'AGO']],
            ['Pump 3', 'P3', ['DPK']],
        ] as [$name, $code, $grades]) {
            $pump = Pump::query()->create(['branch_id' => $this->branch, 'name' => $name, 'code' => $code, 'status' => Pump::ACTIVE]);
            foreach ($grades as $i => $grade) {
                $nozzle = Nozzle::query()->create([
                    'branch_id' => $this->branch,
                    'pump_id' => $pump->id,
                    'fuel_product_id' => $this->products[$grade]->id,
                    'name' => $name.' '.$this->products[$grade]->name,
                    'code' => $code.'-'.chr(65 + $i),
                    'status' => Nozzle::ACTIVE,
                ]);
                $equipment->connect($nozzle, $this->tanks[$grade], $this->owner);
                $this->nozzles[] = $nozzle->fresh();
            }
        }

        foreach ([
            ['Deng Garang', '+211 912 345 101'], ['Achol Mabior', '+211 912 345 102'], ['James Lado', '+211 912 345 103'],
            ['Nyandeng Kuol', '+211 912 345 104'], ['Peter Wani', '+211 912 345 105'], ['Rebecca Nyibol', '+211 912 345 106'],
        ] as $i => [$name, $phone]) {
            $this->attendants[] = $setup->saveAttendant([
                'name' => $name,
                'phone' => $phone,
                'employee_code' => sprintf('ATT-%03d', $i + 1),
                'user_id' => null,
                'active' => true,
            ], null, $this->branch);
        }

        foreach ([
            ['PMS', 'Nile Petroleum Corporation', '+211 920 100 200'],
            ['AGO', 'Trinity Energy', '+211 920 300 400'],
            ['DPK', 'Hass Petroleum', '+211 920 500 600'],
        ] as [$grade, $name, $phone]) {
            $this->suppliers[$grade] = Supplier::query()->create(['name' => $name, 'phone' => $phone, 'active' => true]);
        }

        foreach ([
            [Customer::NGO, 'Hope Relief Mission', 'logistics@hoperelief.example', 6000000000],
            [Customer::GOVERNMENT, 'Ministry of Roads and Bridges', null, 4000000000],
            [Customer::FLEET, 'Juba City Boda Riders Cooperative', null, 1500000000],
            [Customer::CORPORATE, 'Gudele Water Delivery Ltd', 'accounts@gudelewater.example', 2500000000],
        ] as [$kind, $name, $email, $limit]) {
            $this->customers[] = Customer::query()->create([
                'kind' => $kind, 'name' => $name, 'email' => $email,
                'phone' => '+211 955 '.mt_rand(100, 999).' '.mt_rand(100, 999),
                'credit_limit_minor' => $limit, 'active' => true,
            ]);
        }

        foreach ([
            ['RENT', 'Rent', ExpenseCategory::RECURRING], ['SEC', 'Security', ExpenseCategory::RECURRING],
            ['GEN', 'Generator fuel', ExpenseCategory::ONCE], ['CLEAN', 'Cleaning and supplies', ExpenseCategory::ONCE],
            ['WELF', 'Staff welfare', ExpenseCategory::ONCE], ['REP', 'Repairs and maintenance', ExpenseCategory::ONCE],
        ] as [$code, $name, $type]) {
            $this->categories[$code] = ExpenseCategory::query()->create(['code' => $code, 'name' => $name, 'default_type' => $type, 'active' => true]);
        }
    }

    private function history(CarbonImmutable $from, CarbonImmutable $to): void
    {
        $priceRise = $from->addDays(16);
        for ($day = $from; $day->lt($to); $day = $day->addDay()) {
            $this->step(function () use ($day, $from, $priceRise) {
                if ($day->isSameDay($priceRise)) {
                    $this->raisePrices($day);
                }
                if ($day->day === 1) {
                    $this->monthlyBills($day);
                }

                $this->deliveries($day);
                $this->dip($day);
                $this->shift($day, 'Morning', 6, 14, $day->isSameDay($from) ? $this->openings() : []);
                $this->shift($day, 'Evening', 14, 22, []);
                $this->payments($day);
            });
            $this->components->twoColumnDetail($day->toDateString(), $this->sales.' sales');
        }
    }

    /**
     * Run one day in its own transaction, holding back its finance events.
     */
    private function step(callable $work): void
    {
        DB::transaction(function () use ($work) {
            $work();
            DB::table('platform_outbox')->where('status', 'pending')->delete();
            DB::table('jobs')->where('payload', 'like', '%DeliverOutboxEvent%')->delete();
        });
    }

    /**
     * Today: the morning shift is open and selling.
     */
    private function today(): void
    {
        $day = $this->now->startOfDay();
        $this->deliveries($day);
        $this->dip($day);
        $shift = $this->open($day, 'Morning', 6, []);
        $this->sell($shift, $day->setTime(6, 10), min(13, max(7, $this->now->hour)), 0.5);
    }

    /**
     * @param  list<array{nozzle_id: string, reading: string}>  $openings
     */
    private function shift(CarbonImmutable $day, string $schedule, int $from, int $to, array $openings): void
    {
        $shift = $this->open($day, $schedule, $from, $openings);
        $busy = $day->isWeekend() ? 0.8 : 1.0;
        $this->sell($shift, $day->setTime($from, 10), $to, $busy);

        if ($schedule === 'Evening' && mt_rand(1, 3) === 1) {
            $this->expense($shift, $day->setTime(19, 30), 'GEN', 'Diesel for the generator', 3 * 4600000, Expense::CASH);
        }
        if ($schedule === 'Morning' && mt_rand(1, 2) === 1) {
            $this->expense($shift, $day->setTime(10, 15), 'WELF', 'Tea and breakfast for the crew', 1500000, Expense::CASH);
        }
        if ($schedule === 'Morning' && $day->dayOfWeek === 1) {
            $this->expense($shift, $day->setTime(9, 0), 'CLEAN', 'Detergent, rags and forecourt brooms', 2800000, Expense::CASH);
        }

        $this->close($shift, $day->setTime($to, 0));
    }

    /**
     * @param  list<array{nozzle_id: string, reading: string}>  $openings
     */
    private function open(CarbonImmutable $day, string $schedule, int $hour, array $openings): Shift
    {
        $this->clock($day->setTime($hour, 0));
        $scheduleId = ShiftSchedule::query()->where('branch_id', $this->branch)->where('name', $schedule)->value('id');
        $shift = app(Shifts::class)->open($this->station->fresh(), $this->supervisor, 5000000, $scheduleId, null, $openings);

        // Morning crew: attendants 0-2, evening crew 3-5. One on pump 1, one on pump 2, one on kerosene.
        $crew = array_slice($this->attendants, $schedule === 'Morning' ? 0 : 3, 3);
        $this->clock($day->setTime($hour, 5));
        foreach ($this->nozzles as $nozzle) {
            $who = match (true) {
                str_starts_with($nozzle->code, 'P1') => $crew[0],
                str_starts_with($nozzle->code, 'P2') => $crew[1],
                default => $crew[2],
            };
            app(Shifts::class)->assign($shift, $who->id, $nozzle->id, $this->supervisor);
        }

        return $shift;
    }

    private function sell(Shift $shift, CarbonImmutable $at, int $untilHour, float $busy): void
    {
        $sales = app(Sales::class);
        $end = $at->setTime($untilHour, 0);
        $assignments = $shift->assignments()->get()->keyBy('nozzle_id');

        foreach ($this->nozzles as $nozzle) {
            $grade = (string) collect($this->products)->search(fn (FuelProduct $product) => $product->id === $nozzle->fuel_product_id);
            $count = (int) round(($grade === 'DPK' ? mt_rand(3, 6) : mt_rand(8, 13)) * $busy);
            $span = max(1, $at->diffInMinutes($end));

            for ($i = 0; $i < $count; $i++) {
                $this->clock($at->addMinutes((int) ($span * ($i + mt_rand(1, 9) / 10) / max(1, $count))));
                $input = $this->sale($grade, $nozzle, $assignments[$nozzle->id]->attendant_id);
                if ($input === null) {
                    continue;
                }
                $sales->record($this->station->fresh(), $this->supervisor, $input, true);
                $this->sales++;
            }
        }
    }

    /**
     * What one customer buys: boda bodas a few litres, cars a round amount,
     * trucks and account customers by the litre.
     *
     * @return array{nozzle_id: string, litres: ?string, amount: ?string, attendant_id: string, collected_by: string, idempotency_key: ?string, payments: list<mixed>}|null
     */
    private function sale(string $grade, Nozzle $nozzle, string $attendantId): ?array
    {
        $price = (int) FuelPrice::current($this->branch, $nozzle->fuel_product_id)->selling_price_minor;
        $roll = mt_rand(1, 100);
        $customer = null;

        if ($roll <= 9 && $grade !== 'DPK') {
            // Boda riders buy petrol; the others run diesel and petrol vehicles.
            $customer = $grade === 'AGO' ? $this->customers[[0, 1, 3][mt_rand(0, 2)]] : $this->customers[mt_rand(0, 3)];
            $litres = $customer->kind === Customer::FLEET ? mt_rand(3, 6) * 5 : mt_rand(4, 16) * 10;
            $milli = $litres * 1000;
            $available = $customer->credit_limit_minor - app(Credit::class)->balance($customer->id);
            if (Volume::amount($milli, $price) > $available) {
                $customer = null;
            }
        } elseif ($grade === 'PMS' && $roll <= 45) {
            $milli = Volume::milliForAmount([1000000, 1500000, 2000000][mt_rand(0, 2)], $price);
        } elseif ($grade === 'AGO' && $roll <= 22) {
            $milli = mt_rand(8, 25) * 10000;
        } elseif ($grade === 'DPK') {
            $milli = Volume::milliForAmount([500000, 1000000, 2000000][mt_rand(0, 2)], $price);
        } else {
            $milli = Volume::milliForAmount([5000000, 10000000, 15000000, 20000000][mt_rand(0, 3)], $price);
        }

        if (Tank::query()->whereKey($this->tanks[$grade]->id)->value('quantity_milli') < $milli + 500000) {
            return null;
        }

        $total = Volume::amount($milli, $price);
        $method = match (true) {
            $customer !== null => $this->methods[PaymentMethod::CREDIT],
            mt_rand(1, 100) <= 28 => $this->methods[PaymentMethod::MOBILE],
            default => $this->methods[PaymentMethod::CASH],
        };

        return [
            'nozzle_id' => $nozzle->id,
            'litres' => Volume::toLitres($milli),
            'amount' => null,
            'attendant_id' => $attendantId,
            'collected_by' => 'cashier',
            'idempotency_key' => null,
            'payments' => [array_filter([
                'method_id' => $method->id,
                'amount' => Money::toMajor($total),
                'customer_id' => $customer?->id,
                'reference' => $method->kind === PaymentMethod::MOBILE ? 'MG'.mt_rand(10000000, 99999999) : null,
            ])],
        ];
    }

    private function expense(Shift $shift, CarbonImmutable $at, string $category, string $description, int $amount, string $from): void
    {
        $this->clock($at);
        app(Cashbook::class)->expense($this->branch, $shift, $this->categories[$category]->name, $description, $amount, $from, $this->supervisor, null, [
            'category_id' => $this->categories[$category]->id,
        ]);
    }

    private function close(Shift $shift, CarbonImmutable $at): void
    {
        $this->clock($at);
        $shifts = app(Shifts::class);
        $closings = [];
        foreach ($shift->readings()->where('kind', 'opening')->get() as $opening) {
            $sold = (int) $shift->sales()->where('nozzle_id', $opening->nozzle_id)->where('status', 'posted')->sum('quantity_milli');
            $closings[] = ['nozzle_id' => $opening->nozzle_id, 'reading' => Volume::toLitres($opening->reading_milli + $sold)];
        }

        $expected = app(Cashbook::class)->drawer($shift->id);
        $short = mt_rand(1, 12) === 1 ? mt_rand(1, 4) * 500000 : 0;
        $shifts->submit($shift->fresh(), $this->supervisor, Money::toMajor($expected - $short), $closings, [], $short > 0 ? 'Drawer short, change given in error at the rush' : null);

        $this->clock($at->addMinutes(40));
        $shifts->review($shift->fresh(), $this->owner);
        $shifts->approve($shift->fresh(), $this->owner);
    }

    /**
     * @return list<array{nozzle_id: string, reading: string}>
     */
    private function openings(): array
    {
        return array_map(fn (Nozzle $nozzle) => [
            'nozzle_id' => $nozzle->id,
            'reading' => (string) mt_rand(180000, 940000).'.'.sprintf('%03d', mt_rand(0, 999)),
        ], $this->nozzles);
    }

    /**
     * Order when a tank falls under its reorder level; the tanker arrives the
     * next morning, a few litres short of the waybill now and then.
     */
    private function deliveries(CarbonImmutable $day): void
    {
        $purchasing = app(Purchasing::class);
        foreach ($this->tanks as $grade => $tank) {
            $tank = $tank->fresh();
            if (isset($this->ordered[$grade])) {
                $this->clock($day->setTime(7, 30));
                $order = $this->ordered[$grade]->load('lines');
                $line = $order->lines->first();
                $declared = $line->quantity_milli;
                $measured = $declared - (mt_rand(1, 3) === 1 ? mt_rand(20, 90) * 1000 : mt_rand(0, 8) * 1000);
                $this->unpaid[] = $purchasing->receive($this->branch, $this->suppliers[$grade], [
                    'tank_id' => $tank->id,
                    'declared' => Volume::toLitres($declared),
                    'measured' => Volume::toLitres($measured),
                    'unit_cost' => Money::toMajor($this->cost[$grade]),
                    'tanker' => 'SSD '.mt_rand(100, 999).' '.chr(mt_rand(65, 90)).chr(mt_rand(65, 90)),
                    'driver' => ['Abraham Malual', 'Samuel Lokuji', 'Moses Taban'][mt_rand(0, 2)],
                    'supplier_reference' => 'WB-'.mt_rand(40000, 49999),
                    'idempotency_key' => null,
                    'purchase_line_id' => $line->id,
                ], $this->owner);
                unset($this->ordered[$grade]);

                continue;
            }

            if ($tank->quantity_milli < $tank->reorder_milli) {
                $this->clock($day->setTime(6, 30));
                $litres = (int) (floor(($tank->safe_fill_milli - $tank->quantity_milli) / 1000 / 1000) * 1000) - 2000;
                $this->ordered[$grade] = $purchasing->order($this->branch, $this->suppliers[$grade], [[
                    'fuel_product_id' => $tank->fuel_product_id,
                    'litres' => (string) $litres,
                    'unit_cost' => Money::toMajor($this->cost[$grade]),
                ]], $day->addDay()->toDateString(), 'Tank below reorder level', $this->owner);
            }
        }
    }

    /**
     * The morning dip: a little evaporation, now and then a reading above book.
     */
    private function dip(CarbonImmutable $day): void
    {
        $this->clock($day->setTime(5, 45));
        foreach ($this->tanks as $tank) {
            $book = (int) $tank->fresh()->quantity_milli;
            $variance = (int) ($book * mt_rand(-25, 6) / 10000);
            app(TankLedger::class)->measure($tank, max(0, intdiv($book + $variance, 1000) * 1000), $this->supervisor, null, 'Morning dip');
        }
    }

    private function raisePrices(CarbonImmutable $day): void
    {
        $this->clock($day->setTime(5, 30));
        foreach (['PMS' => [450000, 390000], 'AGO' => [480000, 415000], 'DPK' => [400000, 335000]] as $grade => [$price, $cost]) {
            app(StationSetup::class)->setPrice($this->branch, $this->products[$grade], $price, $this->owner);
            $this->cost[$grade] = $cost;
        }
    }

    private function monthlyBills(CarbonImmutable $day): void
    {
        $this->clock($day->setTime(9, 0));
        $cash = app(Cashbook::class);
        foreach ([
            ['RENT', 'Plot rent, '.$day->format('F Y'), 180000000, 'Gudele Land Holdings'],
            ['SEC', 'Night guards, '.$day->format('F Y'), 45000000, 'Shield Security Services'],
        ] as [$code, $description, $amount, $payee]) {
            $cash->expense($this->branch, null, $this->categories[$code]->name, $description, $amount, Expense::BANK, $this->owner, null, [
                'category_id' => $this->categories[$code]->id,
                'payee' => $payee,
            ]);
        }
    }

    /**
     * Account customers settle by bank on Mondays; suppliers are paid a week
     * after delivery.
     */
    private function payments(CarbonImmutable $day): void
    {
        $this->clock($day->setTime(22, 30));
        if ($day->dayOfWeek === 1) {
            $credit = app(Credit::class);
            foreach ($this->customers as $customer) {
                $owed = $credit->balance($customer->id);
                $paid = (int) (floor($owed * mt_rand(55, 90) / 100 / 100000) * 100000);
                if ($paid > 0) {
                    $credit->receive($customer, $this->branch, null, $paid, (string) $this->methods[PaymentMethod::BANK]->financeMethod(), $this->owner, null, 'Bank transfer '.mt_rand(100000, 999999));
                }
            }
        }

        foreach ($this->unpaid as $i => $receipt) {
            if (CarbonImmutable::parse($receipt->received_at)->addDays(7)->gt($day)) {
                continue;
            }
            app(Purchasing::class)->pay($this->branch, Supplier::query()->findOrFail($receipt->supplier_id), null, $receipt->total_minor, Expense::BANK, $receipt->id, 'TT '.$receipt->number, $this->owner, null);
            unset($this->unpaid[$i]);
        }
    }

    private function clock(?CarbonImmutable $at): void
    {
        Carbon::setTestNow($at);
        CarbonImmutable::setTestNow($at);
    }
}
