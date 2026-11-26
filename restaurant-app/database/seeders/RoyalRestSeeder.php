<?php

namespace Database\Seeders;

use App\Actions\Bills;
use App\Actions\Orders;
use App\Actions\Stock;
use App\Actions\Tills;
use App\Enums\PaymentMethod;
use App\Models\Bill;
use App\Models\DiningTable;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\StockLevel;
use App\Models\TillShift;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Miles\PlatformClient\Api\Organization;
use Miles\PlatformClient\Tenancy\CurrentTenant;
use RuntimeException;

/**
 * Royal Rest's month of trading, after central's RoyalRestSeeder made its
 * branches and team (DEMO_BUSINESS_FILE). Everything goes through the
 * app's own actions with the clock set back, so every bill, ticket, till
 * and stock movement carries its own time and adds up: the menu, tables in
 * each branch, opening stock and weekly deliveries, thirty days of service
 * (discounts, split bills, voided dishes, cancellations, refunds, a till
 * short now and then, stock counts), and today still going: tills open,
 * tables at every stage, a slow ticket, drinks running low.
 *
 * Prices are in South Sudanese pounds (minor unit: piastres). Times are
 * Juba's (the app keeps UTC). Run once, on an empty workspace.
 *
 *   DEMO_BUSINESS_FILE=/tmp/royal-rest.json php artisan db:seed --class=RoyalRestSeeder
 */
class RoyalRestSeeder extends Seeder
{
    private const ZONE = 'Africa/Juba';

    private const DAYS = 30;

    /** Branch code => [tables as [name, area, seats], usual orders a day]. */
    public const BRANCHES = [
        'MAIN' => [[
            ['T1', 'Main hall', 4], ['T2', 'Main hall', 4], ['T3', 'Main hall', 4], ['T4', 'Main hall', 6], ['T5', 'Main hall', 6],
            ['G1', 'Garden', 4], ['G2', 'Garden', 4], ['G3', 'Garden', 8], ['VIP', 'VIP room', 10], ['Bar 1', 'Bar', 2],
        ], 26],
        'GUD' => [[
            ['T1', 'Inside', 4], ['T2', 'Inside', 4], ['T3', 'Inside', 6], ['T4', 'Inside', 2],
            ['V1', 'Veranda', 4], ['V2', 'Veranda', 4], ['V3', 'Veranda', 6],
        ], 17],
        'MUN' => [[
            ['T1', 'Inside', 4], ['T2', 'Inside', 4], ['T3', 'Inside', 2], ['T4', 'Inside', 6],
            ['Tree 1', 'Under the mango tree', 6], ['Tree 2', 'Under the mango tree', 8],
        ], 12],
    ];

    /** [name, section, price in SSP, how often it is ordered]. */
    public const DISHES = [
        ['Ful medames with bread', 'Breakfast', 8000, 4],
        ['Chapati and eggs', 'Breakfast', 6000, 5],
        ['Tea and mandazi', 'Breakfast', 4000, 5],
        ['Beef stew with kisra', 'Mains', 18000, 8],
        ['Goat stew with asida', 'Mains', 22000, 6],
        ['Grilled tilapia with chips', 'Mains', 30000, 6],
        ['Half grilled chicken with rice', 'Mains', 25000, 7],
        ['Beef pilau', 'Mains', 16000, 7],
        ['Beans and rice', 'Mains', 10000, 8],
        ['Mixed vegetables with rice', 'Mains', 9000, 4],
        ['Kawari soup', 'Mains', 14000, 3],
        ['Nyama choma (1 kg)', 'Grill', 45000, 3],
        ['Beef samosas (3)', 'Snacks', 5000, 4],
        ['Chips', 'Snacks', 7000, 5],
        ['Fruit salad', 'Desserts', 6000, 2],
        ['Coffee', 'Hot drinks', 3000, 4],
    ];

    /** [name, unit, price, cost, reorder level, how often, barcode]. */
    public const DRINKS = [
        ['Bottled water 500 ml', 'bottle', 2000, 1100, 48, 9, 'RR-W500'],
        ['Coca-Cola 300 ml', 'bottle', 3000, 1800, 48, 8, 'RR-COKE'],
        ['Fanta orange 300 ml', 'bottle', 3000, 1800, 36, 5, 'RR-FANTA'],
        ['Mango juice 350 ml', 'bottle', 5000, 3000, 24, 4, 'RR-MANGO'],
        ['Malt drink 330 ml', 'bottle', 4000, 2400, 24, 3, 'RR-MALT'],
        ['White Bull beer 500 ml', 'bottle', 6000, 3800, 48, 6, 'RR-WBULL'],
        ['Nile Special beer 500 ml', 'bottle', 7000, 4500, 36, 4, 'RR-NILE'],
    ];

    /** @var array<string, User> */
    private array $team = [];

    /** @var array<string, string> code => branch id */
    private array $branches = [];

    /** @var array<string, list<DiningTable>> */
    private array $tables = [];

    /** @var list<array{item: MenuItem, weight: int}> */
    private array $menu = [];

    /** @var list<MenuItem> */
    private array $drinks = [];

    private Orders $orders;

    private Tills $tills;

    private Stock $stock;

    private CarbonImmutable $now;

    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('RoyalRestSeeder only runs with APP_ENV=local.');
        }

        $data = json_decode((string) file_get_contents((string) getenv('DEMO_BUSINESS_FILE')), true, flags: JSON_THROW_ON_ERROR);
        $organization = Organization::fromArray($data['organization']);
        $this->branches = $data['branches'];
        $this->orders = app(Orders::class);
        $this->tills = app(Tills::class);
        $this->stock = app(Stock::class);
        $this->now = CarbonImmutable::now(self::ZONE);
        mt_srand(2026);

        app(CurrentTenant::class)->run($organization, function () use ($data, $organization) {
            if (MenuItem::query()->exists()) {
                throw new RuntimeException('Royal Rest already has a menu: this seeder runs once, on an empty workspace.');
            }

            try {
                foreach ($data['users'] as $key => $user) {
                    $member = User::query()->whereKey($user['id'])->first() ?? new User;
                    $member->forceFill($user)->save();
                    $this->team[$key] = $member;
                }

                $start = $this->now->startOfDay()->subDays(self::DAYS);
                DB::transaction(fn () => $this->setUp($start));

                for ($day = $start; $day->lt($this->now->startOfDay()); $day = $day->addDay()) {
                    DB::transaction(fn () => $this->trade($day));
                    $this->command?->getOutput()->write('.');
                }
                DB::transaction(fn () => $this->today());
                $this->command?->newLine();

                // Royal Rest keeps no books in Finance: nothing to tell it.
                DB::table('platform_outbox')->where('organization_id', $organization->id)->where('status', 'pending')->delete();
            } finally {
                $this->clock(null);
            }

            $this->command?->info(sprintf('Royal Rest: %d orders, %d bills, %d tills.', Order::query()->count(), Bill::query()->count(), TillShift::query()->count()));
        });
    }

    private function setUp(CarbonImmutable $start): void
    {
        $this->at($start->setTime(7, 0));

        foreach (self::BRANCHES as $code => [$tables]) {
            foreach ($tables as [$name, $area, $seats]) {
                $this->tables[$code][] = DiningTable::query()->create(['branch_id' => $this->branches[$code], 'name' => $name, 'area' => $area, 'seats' => $seats, 'active' => true]);
            }
        }

        foreach (self::DISHES as [$name, $section, $price, $weight]) {
            $item = MenuItem::query()->create(['kind' => MenuItem::DISH, 'name' => $name, 'section' => $section, 'unit' => 'plate', 'price_minor' => $price * 100, 'active' => true]);
            $this->menu[] = ['item' => $item, 'weight' => $weight];
        }

        $keeper = $this->team['store_keeper'];
        foreach (self::DRINKS as [$name, $unit, $price, $cost, $reorder, $weight, $barcode]) {
            $drink = MenuItem::query()->create(['kind' => MenuItem::DRINK, 'name' => $name, 'section' => 'Cold drinks', 'unit' => $unit, 'price_minor' => $price * 100, 'barcode' => $barcode, 'reorder_level' => $reorder, 'active' => true]);
            $this->menu[] = ['item' => $drink, 'weight' => $weight];
            $this->drinks[] = $drink;
            foreach ($this->branches as $branchId) {
                $this->stock->opening($drink, $branchId, $reorder * 3, $cost * 100, $keeper, 'Opening stock');
            }
        }
    }

    /** A full day in every branch, from opening the tills to closing them. */
    private function trade(CarbonImmutable $day): void
    {
        $last = $day->addDay()->isSameDay($this->now);

        foreach (self::BRANCHES as $code => [, $usual]) {
            $this->restock($code, $day);

            $cashier = $this->team[strtolower($code).'_cashier'];
            $this->at($day->setTime(7, 40));
            $till = $this->tills->openFor($cashier, $this->branches[$code]) ?? $this->tills->open($cashier, $this->branches[$code], 200_000 * 100);

            $count = (int) round($usual * ($day->isWeekend() ? 1.35 : 1.0) * mt_rand(80, 120) / 100);
            foreach ($this->times($day, $count, 8 * 60, 21 * 60) as $minute) {
                $this->serve($code, $day->addMinutes($minute));
            }

            // Every two weeks the store keeper counts the drinks.
            if ($day->dayOfWeek === CarbonImmutable::SUNDAY && $day->weekOfYear % 2 === 0) {
                $this->count($code, $day->setTime(21, 50));
            }

            // Gudele's cashier forgets to close the till the night before today.
            if ($last && $code === 'GUD') {
                continue;
            }

            $this->at($day->setTime(22, 0));
            $till = $till->fresh();
            $expected = $this->tills->summary($till)['expectedCashMinor'];
            $short = mt_rand(1, 12) === 1;
            $this->tills->close($till, $cashier, $short ? $expected - 5_000 * 100 : $expected, $short ? 'Short by 5,000: change given in error' : null);
        }
    }

    /** Today, up to now: service going on, tables at every stage. */
    private function today(): void
    {
        $day = $this->now->startOfDay();
        $until = (int) floor(($this->now->hour * 60 + $this->now->minute) - 50);
        if ($until < 8 * 60 + 30) {
            return;
        }

        foreach (self::BRANCHES as $code => [, $usual]) {
            $branchId = $this->branches[$code];
            $cashier = $this->team[strtolower($code).'_cashier'];
            $this->restock($code, $day);
            $this->at($day->setTime(7, 40));
            $this->tills->openFor($cashier, $branchId) ?? $this->tills->open($cashier, $branchId, 200_000 * 100);

            $count = (int) round($usual * ($until - 8 * 60) / (13 * 60));
            foreach ($this->times($day, $count, 8 * 60, $until) as $minute) {
                $this->serve($code, $day->addMinutes($minute));
            }

            // Still at the tables.
            $waiter = $this->waiter($code);
            $tables = $this->tables[$code];
            $start = $day->addMinutes($until);

            // Main course served, the bill not asked for yet.
            $this->at($start);
            $served = $this->orders->open($waiter, $branchId, Order::DINE_IN, $tables[0]->id, 4);
            $this->orders->add($served, $waiter, $this->lines(3));
            $ticket = $this->orders->send($served->fresh(), $waiter);
            $this->at($start->addMinutes(18));
            $this->orders->ready($ticket);
            $this->orders->served($ticket->fresh());

            // Ready at the pass.
            $this->at($start->addMinutes(20));
            $ready = $this->orders->open($waiter, $branchId, Order::DINE_IN, $tables[1]->id, 2);
            $this->orders->add($ready, $waiter, [['item_id' => $this->dish('Grilled tilapia with chips')->id, 'quantity' => 2, 'note' => 'One without chilli']]);
            $ticket = $this->orders->send($ready->fresh(), $waiter);
            $this->at($start->addMinutes(38));
            $this->orders->ready($ticket);

            // Waiting too long in the kitchen (Main only): the overview flags it.
            if ($code === 'MAIN') {
                $this->at($start->addMinutes(5));
                $slow = $this->orders->open($waiter, $branchId, Order::DINE_IN, $tables[8]->id, 8);
                $this->orders->add($slow, $waiter, [['item_id' => $this->dish('Nyama choma (1 kg)')->id, 'quantity' => 3], ['item_id' => $this->dish('Chips')->id, 'quantity' => 4]]);
                $this->orders->send($slow->fresh(), $waiter);
            }

            // In the kitchen now.
            $this->at($start->addMinutes(40));
            $cooking = $this->orders->open($waiter, $branchId, Order::TAKEAWAY, null, null, 'Boda driver, Konyo Konyo');
            $this->orders->add($cooking, $waiter, $this->lines(2));
            $this->orders->send($cooking->fresh(), $waiter);

            // Just sat down, still choosing.
            $this->at($start->addMinutes(45));
            $choosing = $this->orders->open($waiter, $branchId, Order::DINE_IN, $tables[2]->id, 3);
            $this->orders->add($choosing, $waiter, $this->lines(2));
        }
    }

    /** One party, from sitting down to paying, with what goes wrong sometimes. */
    private function serve(string $code, CarbonImmutable $at): void
    {
        $branchId = $this->branches[$code];
        $waiter = $this->waiter($code);
        $cashier = $this->team[strtolower($code).'_cashier'];
        $this->at($at);

        $dineIn = mt_rand(1, 100) <= 72;
        $guests = mt_rand(1, 5);
        $order = $dineIn
            ? $this->orders->open($waiter, $branchId, Order::DINE_IN, $this->tables[$code][array_rand($this->tables[$code])]->id, $guests)
            : $this->orders->open($waiter, $branchId, Order::TAKEAWAY, null, null, $this->customer());
        $this->orders->add($order, $waiter, $this->lines($dineIn ? min(5, $guests + mt_rand(0, 2)) : mt_rand(1, 3)));

        $this->at($at->addMinutes(2));
        $ticket = $this->orders->send($order->fresh(), $waiter);

        // Walked out before the food came.
        if (mt_rand(1, 60) === 1) {
            $this->at($at->addMinutes(12));
            $this->orders->cancel($order->fresh(), $this->manager($code), mt_rand(0, 1) ? 'Customer left before the food was ready' : 'Ordered at the wrong table');
            $this->orders->clear($ticket->fresh());

            return;
        }

        $this->at($at->addMinutes(mt_rand(12, 28)));
        $this->orders->ready($ticket);
        $this->at($at->addMinutes(30));
        $this->orders->served($ticket->fresh());

        // A dish sent back.
        if (mt_rand(1, 35) === 1) {
            $live = OrderLine::query()->where('order_id', $order->id)->whereNull('voided_at')->get();
            $line = $live->count() > 1 ? $live->first() : null;
            if ($line !== null) {
                $this->orders->void($order->fresh(), $line, $this->manager($code), mt_rand(0, 1) ? 'Sent back: cold' : 'Customer changed their mind');
            }
        }

        $this->at($at->addMinutes(mt_rand(40, 75)));
        $method = $this->method();
        $reference = fn () => $method === PaymentMethod::Cash ? null : strtoupper(substr($method->value, 0, 2)).'-'.mt_rand(100000, 999999);
        $roll = mt_rand(1, 100);

        if ($dineIn && $guests >= 3 && $roll <= 4) {
            // Friends paying their own shares.
            $parts = min(3, $guests);
            for ($i = 0; $i < $parts; $i++) {
                $this->orders->pay($order->fresh(), $cashier, $method, null, $reference(), parts: $parts);
            }

            return;
        }

        $discount = match (true) {
            $roll >= 96 => ['percent' => 10, 'reason' => 'Regular customer'],
            $roll === 95 => ['percent' => 100, 'reason' => 'Manager\'s guest'],
            $roll === 94 => ['amount_minor' => 5_000 * 100, 'reason' => 'Long wait for food'],
            default => null,
        };

        try {
            $bill = $this->orders->pay($order->fresh(), $cashier, $method, null, $reference(), discount: $discount);
        } catch (ValidationException) {
            // A discount larger than a small bill: paid in full instead.
            $bill = $this->orders->pay($order->fresh(), $cashier, $method, null, $reference());
        }

        // Charged to the wrong table: refunded the same day.
        if (mt_rand(1, 150) === 1 && $bill->total_minor > 0) {
            $this->at($at->addMinutes(80));
            app(Bills::class)->void($bill->fresh(), $cashier, 'Charged to the wrong table');
        }
    }

    /** Monday deliveries, and any day a drink is about to run out. */
    private function restock(string $code, CarbonImmutable $day): void
    {
        $branchId = $this->branches[$code];
        $lines = [];
        foreach ($this->drinks as $drink) {
            // Munuki's mango juice and Gudele's malt are not ordered in the last days: they run low.
            $holdBack = $day->diffInDays($this->now->startOfDay()) <= 5
                && (($code === 'MUN' && $drink->barcode === 'RR-MANGO') || ($code === 'GUD' && $drink->barcode === 'RR-MALT'));
            $level = (int) StockLevel::query()->where('branch_id', $branchId)->where('menu_item_id', $drink->id)->value('quantity');
            $target = $drink->reorder_level * 3;
            if ($holdBack || ($level > $drink->reorder_level && ! ($day->isMonday() && $level < $target))) {
                continue;
            }
            $quantity = (int) (ceil(($target - $level) / 24) * 24);
            if ($quantity > 0) {
                $cost = (int) collect(self::DRINKS)->first(fn (array $row) => $row[6] === $drink->barcode)[3];
                $lines[] = ['item' => $drink, 'quantity' => $quantity, 'unit_cost_minor' => $cost * 100];
            }
        }
        if ($lines === []) {
            return;
        }

        $this->at($day->setTime(7, 15));
        $beer = array_filter($lines, fn (array $line) => str_contains($line['item']->name, 'beer'));
        $soft = array_filter($lines, fn (array $line) => ! str_contains($line['item']->name, 'beer'));
        if ($soft !== []) {
            $this->stock->deliver($branchId, 'Juba Beverages Ltd', 'JB-'.mt_rand(10000, 99999), array_values($soft), $this->team['store_keeper']);
        }
        if ($beer !== []) {
            $this->stock->deliver($branchId, 'Nile Breweries depot', 'SAB-'.mt_rand(10000, 99999), array_values($beer), $this->team['store_keeper']);
        }
    }

    /** The store keeper's count: a bottle or two broken or gone. */
    private function count(string $code, CarbonImmutable $at): void
    {
        $this->at($at);
        foreach ($this->drinks as $drink) {
            if (mt_rand(1, 3) === 1) {
                $this->stock->adjust($drink, $this->branches[$code], -mt_rand(1, 2), 'damaged', $this->team['store_keeper'], 'Found broken at the count');
            }
        }
    }

    /**
     * What a party orders: dishes and drinks, the popular ones more often.
     *
     * @return list<array{item_id: string, quantity: int}>
     */
    private function lines(int $count): array
    {
        $total = array_sum(array_column($this->menu, 'weight'));
        $picked = [];
        for ($i = 0; $i < max(1, $count); $i++) {
            $roll = mt_rand(1, $total);
            foreach ($this->menu as $entry) {
                $roll -= $entry['weight'];
                if ($roll <= 0) {
                    $picked[$entry['item']->id] = ($picked[$entry['item']->id] ?? 0) + 1;
                    break;
                }
            }
        }

        return array_map(fn (string $id, int $quantity) => ['item_id' => $id, 'quantity' => $quantity], array_keys($picked), array_values($picked));
    }

    /**
     * Minutes after midnight, in order, spread over the day with lunch and
     * dinner busier.
     *
     * @return list<int>
     */
    private function times(CarbonImmutable $day, int $count, int $from, int $to): array
    {
        $peaks = [13 * 60, 19 * 60 + 30];
        $times = [];
        for ($i = 0; $i < $count; $i++) {
            $minute = mt_rand(1, 10) <= 6
                ? $peaks[array_rand($peaks)] + mt_rand(-75, 75)
                : mt_rand($from, $to);
            $times[] = max($from, min($to, $minute));
        }
        sort($times);

        return $times;
    }

    private function method(): PaymentMethod
    {
        $roll = mt_rand(1, 100);

        return match (true) {
            $roll <= 55 => PaymentMethod::Cash,
            $roll <= 85 => PaymentMethod::MobileMoney,
            $roll <= 95 => PaymentMethod::Card,
            default => PaymentMethod::BankTransfer,
        };
    }

    private function customer(): string
    {
        $names = ['Deng', 'Akech', 'Lokule', 'Nyibol', 'Wani', 'Ladu', 'Ayen', 'Kuol', 'Office order, UNMISS road', 'Phone order', 'Boda driver'];

        return $names[array_rand($names)];
    }

    private function waiter(string $code): User
    {
        return $this->team[strtolower($code).'_waiter_'.mt_rand(1, 2)];
    }

    private function manager(string $code): User
    {
        return $this->team[strtolower($code).'_manager'];
    }

    private function dish(string $name): MenuItem
    {
        return collect($this->menu)->pluck('item')->firstWhere('name', $name) ?? throw new RuntimeException("No {$name}.");
    }

    /** Sets the clock to a Juba time; the app keeps UTC. */
    private function at(CarbonImmutable $juba): void
    {
        $this->clock($juba->utc()->toDateTimeString());
    }

    private function clock(?string $at): void
    {
        Carbon::setTestNow($at);
        CarbonImmutable::setTestNow($at);
    }
}
