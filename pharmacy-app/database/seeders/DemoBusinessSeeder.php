<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\MasterData\Models\Supplier;
use App\Modules\Pharmacy\Actions\Prescriptions;
use App\Modules\Pharmacy\Models\Medicine;
use App\Modules\Procurement\Actions\Purchasing;
use App\Modules\Procurement\Actions\ReceiveGoods;
use App\Modules\Retail\Models\Product;
use App\Modules\Sales\Actions\Sell;
use App\Modules\Sales\Actions\Tills;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Models\Customer;
use App\Platform\SetUpWorkspace;
use App\Support\DocumentNumbers;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Miles\PlatformClient\Api\Organization;
use Miles\PlatformClient\PlatformConfig;
use Miles\PlatformClient\Tenancy\CurrentTenant;
use RuntimeException;

/**
 * Both products this app serves for the demo business central's
 * DemoBusinessSeeder made (DEMO_BUSINESS_FILE), a water utility: the staff
 * clinic's dispensary (Pharmacy) and the store selling meters and fittings
 * (Retail). Entered through the app's services with the clock set back, so
 * purchases and sales carry their own dates. Amounts are in cents.
 */
class DemoBusinessSeeder extends Seeder
{
    /** @var array<string, User> */
    private array $team = [];

    private string $branch = '';

    /** @var array<string, string> */
    private array $medicineIds = [];

    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('DemoBusinessSeeder only runs with APP_ENV=local.');
        }

        $data = json_decode((string) file_get_contents((string) getenv('DEMO_BUSINESS_FILE')), true, flags: JSON_THROW_ON_ERROR);
        $organization = Organization::fromArray($data['organization']);
        $this->branch = $data['branch_id'];

        app(CurrentTenant::class)->run($organization, function () use ($data, $organization) {
            try {
                DB::transaction(function () use ($data, $organization) {
                    app(SetUpWorkspace::class)->provision($organization);

                    foreach ($data['users'] as $key => $user) {
                        $member = new User;
                        $member->forceFill($user)->save();
                        $this->team[$key] = $member;
                    }

                    $platform = app(PlatformConfig::class);
                    $platform->runAs('retail', fn () => $this->seedStore());
                    $platform->runAs('pharmacy', fn () => $this->seedDispensary());
                    $this->holdBackEvents();
                });
            } finally {
                $this->clock(null);
            }
        });
    }

    /**
     * The store: meters, pipe and fittings, for contractors and walk-ins.
     */
    private function seedStore(): void
    {
        $this->clock('2026-08-03 08:30');

        $products = [];
        foreach ([
            ['15 mm water meter', 'each', 'Meters', 4500, 20, 'MTR-015', 2900, 120],
            ['20 mm water meter', 'each', 'Meters', 6500, 15, 'MTR-020', 4200, 80],
            ['Prepaid meter token card', 'each', 'Meters', 300, 50, 'MTR-TKN', 120, 400],
            ['HDPE pipe 25 mm, per metre', 'metre', 'Pipe', 180, 200, 'PIP-025', 95, 2000],
            ['HDPE pipe 32 mm, per metre', 'metre', 'Pipe', 260, 150, 'PIP-032', 150, 1500],
            ['Gate valve 25 mm', 'each', 'Fittings', 1400, 15, 'FIT-G25', 820, 100],
            ['Compression elbow 25 mm', 'each', 'Fittings', 350, 40, 'FIT-E25', 190, 300],
            ['Tee connector 25 mm', 'each', 'Fittings', 420, 40, 'FIT-T25', 240, 260],
            ['Stopcock 15 mm', 'each', 'Fittings', 900, 20, 'FIT-S15', 520, 120],
            ['1,000 litre storage tank', 'each', 'Storage', 18500, 4, 'TNK-100', 13200, 20],
            ['Thread seal tape', 'roll', 'Sundries', 80, 30, 'SUN-TPE', 35, 500],
        ] as [$name, $unit, $category, $price, $reorder, $barcode, $cost, $quantity]) {
            $product = Product::query()->create(['name' => $name, 'unit' => $unit, 'category' => $category, 'sell_price_minor' => $price, 'reorder_level' => $reorder, 'barcode' => $barcode, 'restricted' => false, 'controlled' => false, 'active' => true, 'tracks_serials' => false]);
            $products[] = ['item' => $product, 'cost' => $cost, 'quantity' => $quantity];
        }

        $pipes = Supplier::query()->create(['name' => 'Nile Pipe Supplies', 'phone' => '+211 927 000 501', 'email' => 'sales@nile-pipe.test', 'address' => 'Custom Market, Juba', 'active' => true]);
        $meters = Supplier::query()->create(['name' => 'Aqua Meter Trading', 'phone' => '+211 927 000 502', 'email' => 'orders@aqua-meter.test', 'address' => 'Nairobi Road, Juba', 'active' => true]);

        $store = $this->team['storekeeper'];
        $receive = app(ReceiveGoods::class);
        $receive->handle($store, $this->branch, $meters, null, 'AMT-3301', CarbonImmutable::parse('2026-08-03'), $this->receiptLines(array_slice($products, 0, 3), 'AUG26-M'));
        $receive->handle($store, $this->branch, $pipes, null, 'NPS-8812', CarbonImmutable::parse('2026-08-03'), $this->receiptLines(array_slice($products, 3), 'AUG26-P'));

        $numbers = app(DocumentNumbers::class);
        $accounts = [];
        foreach ([
            ['Sherikat Construction', '+211 922 000 305', 'site@sherikat-build.test', 'Sherikat, Juba', 500000, 30],
            ['Lasu Plumbing Works', '+211 922 000 306', 'lasu@lasu-plumbing.test', 'Munuki, Juba', 200000, 14],
        ] as [$name, $phone, $email, $address, $limit, $terms]) {
            $accounts[] = Customer::query()->create(['application' => 'retail', 'number' => $numbers->next('customer', 'C'), 'name' => $name, 'phone' => $phone, 'email' => $email, 'address' => $address, 'credit_limit_minor' => $limit, 'payment_terms_days' => $terms, 'active' => true]);
        }

        // Sales each Monday, Wednesday and Friday from mid-August.
        $tills = app(Tills::class);
        $sell = app(Sell::class);
        $cashier = $this->team['shop_cashier'];
        for ($n = 0, $day = CarbonImmutable::parse('2026-08-17'); $day->lt('2026-11-04'); $day = $day->addDay()) {
            if (! in_array($day->dayOfWeek, [1, 3, 5], true)) {
                continue;
            }

            $this->clock($day->setTime(8, 0)->toDateTimeString());
            $till = $tills->open($cashier, $this->branch, 10000);

            for ($s = 0; $s < 3; $s++, $n++) {
                $this->clock($day->setTime(9 + $s * 2, 15)->toDateTimeString());
                $basket = [
                    ['item_id' => $products[6 + $n % 3]['item']->id, 'quantity' => 1 + $n % 3],
                    ['item_id' => $products[10]['item']->id, 'quantity' => 1 + $n % 2],
                ];
                if ($n % 4 === 0) {
                    $basket[] = ['item_id' => $products[3]['item']->id, 'quantity' => 5 + $n % 10];
                }
                if ($n % 5 === 0) {
                    $basket[] = ['item_id' => $products[$n % 2]['item']->id, 'quantity' => 1];
                }

                if ($n % 11 === 0) {
                    $sell->handle($cashier, $this->branch, $basket, PaymentMethod::Account, 'LPO-'.$n, customerId: $accounts[$n % 2]->id);
                } elseif ($n % 3 === 0) {
                    $sell->handle($cashier, $this->branch, $basket, PaymentMethod::MobileMoney, 'MM-'.$n);
                } else {
                    $sell->handle($cashier, $this->branch, $basket, PaymentMethod::Cash, null, 500000);
                }
            }

            $this->clock($day->setTime(16, 30)->toDateTimeString());
            $tills->close($till->fresh(), $cashier, $tills->summary($till->fresh())['expectedCashMinor'], null);
        }

        // On order: a shop running low on stopcocks.
        $this->clock('2026-11-02 10:00');
        $order = app(Purchasing::class)->createOrder($store, $this->branch, $pipes, [
            ['item_id' => $products[8]['item']->id, 'quantity' => 50, 'unit_cost_minor' => 520],
            ['item_id' => $products[5]['item']->id, 'quantity' => 40, 'unit_cost_minor' => 820],
        ], CarbonImmutable::parse('2026-11-16'), 'For the Munuki connections');
        app(Purchasing::class)->markOrdered($order);
    }

    /**
     * @param  array<array{item: Product, cost: int, quantity: int}>  $rows
     * @return list<array{item_id: string, batch_number: string, quantity: int, unit_cost_minor: int}>
     */
    private function receiptLines(array $rows, string $batch): array
    {
        return array_values(array_map(fn (array $row) => [
            'item_id' => (string) $row['item']->getKey(),
            'batch_number' => $batch,
            'quantity' => $row['quantity'],
            'unit_cost_minor' => $row['cost'],
        ], $rows));
    }

    /**
     * The staff clinic's dispensary.
     */
    private function seedDispensary(): void
    {
        $this->clock('2026-09-01 08:30');

        $medicines = [];
        foreach ([
            ['Paracetamol', 'Paracetamol', 'tablet', '500 mg', 'tablet', 'Pain and fever', false, 10, 100, 2000, 3, '2028-03-31'],
            ['Ibuprofen', 'Ibuprofen', 'tablet', '400 mg', 'tablet', 'Pain and fever', false, 15, 100, 1000, 6, '2028-01-31'],
            ['Zinc sulphate', 'Zinc sulphate', 'tablet', '20 mg', 'tablet', 'Stomach', false, 50, 40, 90, 25, '2027-06-30'],
            ['Vitamin C', 'Ascorbic acid', 'tablet', '100 mg', 'tablet', 'Supplements', false, 5, 100, 1500, 1, '2028-02-29'],
            ['Antiseptic cream', 'Cetrimide', 'cream', '0.5%', 'tube', 'Skin', false, 250, 15, 120, 130, '2027-11-30'],
            ['Amoxicillin', 'Amoxicillin', 'capsule', '500 mg', 'capsule', 'Antibiotics', true, 25, 100, 1000, 12, '2026-12-20'],
            ['Artemether-Lumefantrine', 'Artemether-Lumefantrine', 'tablet', '20/120 mg', 'tablet', 'Malaria', true, 45, 96, 960, 26, '2027-08-31'],
            ['Metronidazole', 'Metronidazole', 'tablet', '400 mg', 'tablet', 'Antibiotics', true, 20, 100, 800, 9, '2027-10-31'],
            ['Metformin', 'Metformin', 'tablet', '500 mg', 'tablet', 'Chronic care', true, 15, 60, 500, 7, '2027-12-31'],
        ] as [$name, $generic, $form, $strength, $unit, $category, $prescription, $price, $reorder, $quantity, $cost, $expires]) {
            $medicine = Medicine::query()->create([
                'name' => $name, 'generic_name' => $generic, 'form' => $form, 'strength' => $strength, 'unit' => $unit, 'category' => $category, 'storage' => 'room',
                'prescription_only' => $prescription, 'controlled' => false, 'sell_price_minor' => $price, 'reorder_level' => $reorder, 'active' => true,
            ]);
            $this->medicineIds[$name] = (string) $medicine->getKey();
            $medicines[$name] = ['item' => $medicine, 'quantity' => $quantity, 'cost' => $cost, 'expires' => $expires];
        }

        $supplier = Supplier::query()->create(['name' => 'Equatoria Medical Supplies', 'phone' => '+211 927 000 503', 'email' => 'orders@equatoria-medical.test', 'address' => 'Hai Amarat, Juba', 'active' => true]);
        app(ReceiveGoods::class)->handle($this->team['storekeeper'], $this->branch, $supplier, null, 'EMS-2207', CarbonImmutable::parse('2026-09-01'), array_values(array_map(fn (array $row) => [
            'item_id' => $row['item']->getKey(),
            'batch_number' => 'B'.substr(md5($row['item']->name), 0, 6),
            'expires_on' => $row['expires'],
            'quantity' => $row['quantity'],
            'unit_cost_minor' => $row['cost'],
        ], $medicines)));

        $id = fn (string $name): string => $this->medicineIds[$name];
        $tills = app(Tills::class);
        $sell = app(Sell::class);
        $prescriptions = app(Prescriptions::class);
        $cashier = $this->team['shop_cashier'];
        $pharmacist = $this->team['pharmacist'];
        $patients = ['Moses Lomoro', 'Betty Kaku', 'Ajak Bior', 'Stella Gune', 'Wilson Gore', 'Kenyi Lasu', 'Nelson Yugu', 'Rose Ropani'];
        $courses = [
            ['Amoxicillin', 21, 'One capsule three times a day for seven days'],
            ['Artemether-Lumefantrine', 24, 'Four tablets twice a day for three days'],
            ['Metronidazole', 15, 'One tablet three times a day for five days'],
            ['Metformin', 60, 'One tablet twice a day with food'],
        ];

        // The clinic, three working weeks.
        $record = fn (int $n, string $on) => $prescriptions->record($pharmacist, $this->branch, [
            'patient_name' => $patients[$n % count($patients)],
            'patient_age' => 24 + $n * 3 % 30,
            'prescriber_name' => 'Agnes Yangi',
            'prescriber_registration' => 'SSMC-1042',
            'issued_on' => $on,
        ], [['medicine_id' => $id($courses[$n % 4][0]), 'quantity' => $courses[$n % 4][1], 'directions' => $courses[$n % 4][2]]]);

        for ($n = 0, $day = CarbonImmutable::parse('2026-10-12'); $day->lt('2026-11-04'); $day = $day->addDay()) {
            if ($day->isWeekend()) {
                continue;
            }

            $this->clock($day->setTime(8, 0)->toDateTimeString());
            $till = $tills->open($cashier, $this->branch, 5000);

            for ($s = 0; $s < 3; $s++, $n++) {
                $this->clock($day->setTime(9 + $s, 20)->toDateTimeString());
                $sell->handle($cashier, $this->branch, [
                    ['item_id' => $id(['Paracetamol', 'Ibuprofen', 'Zinc sulphate', 'Vitamin C', 'Antiseptic cream'][$n % 5]), 'quantity' => 1 + $n % 3 * 5],
                ], $n % 4 === 3 ? PaymentMethod::MobileMoney : PaymentMethod::Cash, $n % 4 === 3 ? 'MM-'.$n : null, $n % 4 === 3 ? null : 50000);
            }

            $this->clock($day->setTime(11, 30)->toDateTimeString());
            $prescription = $record($n, $day->toDateString());
            $prescriptions->verify($prescription, $pharmacist);
            $this->clock($day->setTime(11, 50)->toDateTimeString());
            $prescriptions->dispense($prescription->fresh(), $cashier, PaymentMethod::Cash, null, 100000);

            $this->clock($day->setTime(16, 30)->toDateTimeString());
            $tills->close($till->fresh(), $cashier, $tills->summary($till->fresh())['expectedCashMinor'], null);
        }

        // Today: a till open, prescriptions waiting at each step.
        $this->clock('2026-11-04 08:00');
        $tills->open($cashier, $this->branch, 5000);
        $this->clock('2026-11-04 09:15');
        $sell->handle($cashier, $this->branch, [['item_id' => $id('Paracetamol'), 'quantity' => 10]], PaymentMethod::Cash, null, 5000);
        $this->clock('2026-11-04 09:40');
        $prescriptions->verify($record(1, '2026-11-04'), $pharmacist);
        $record(2, '2026-11-04');
        $prescriptions->reject($record(0, '2026-11-04'), $pharmacist, 'Allergic to penicillin: ask the prescriber for another antibiotic.');

        // Running short: more zinc on order.
        app(Purchasing::class)->createOrder($this->team['storekeeper'], $this->branch, $supplier, [
            ['item_id' => $id('Zinc sulphate'), 'quantity' => 200, 'unit_cost_minor' => 25],
        ], CarbonImmutable::parse('2026-11-11'), 'Before the rains peak');
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
