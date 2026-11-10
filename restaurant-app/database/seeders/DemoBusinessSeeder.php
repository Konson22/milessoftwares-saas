<?php

namespace Database\Seeders;

use App\Actions\Orders;
use App\Actions\Stock;
use App\Actions\Tills;
use App\Enums\PaymentMethod;
use App\Models\DiningTable;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\User;
use App\Platform\SetUpWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Miles\PlatformClient\Api\Organization;
use Miles\PlatformClient\Tenancy\CurrentTenant;
use RuntimeException;

/**
 * The staff canteen of the demo business central's DemoBusinessSeeder made
 * (DEMO_BUSINESS_FILE), entered through the app's services with the clock set
 * back, so three weeks of lunches carry their own dates. Amounts are in cents.
 */
class DemoBusinessSeeder extends Seeder
{
    /** @var array<string, User> */
    private array $team = [];

    private string $branch = '';

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

                    $this->seedCanteen();
                    $this->holdBackEvents();
                });
            } finally {
                $this->clock(null);
            }
        });
    }

    private function seedCanteen(): void
    {
        $this->clock('2026-10-09 07:30');

        $tables = [];
        foreach ([
            ['Table 1', 'Main hall', 4], ['Table 2', 'Main hall', 4], ['Table 3', 'Main hall', 6], ['Table 4', 'Main hall', 6],
            ['Table 5', 'Veranda', 4], ['Table 6', 'Veranda', 2], ['Meeting room', 'Veranda', 10],
        ] as [$name, $area, $seats]) {
            $tables[] = DiningTable::query()->create(['branch_id' => $this->branch, 'name' => $name, 'area' => $area, 'seats' => $seats, 'active' => true]);
        }

        $dishes = [];
        foreach ([
            ['Beans and rice', 'Lunch', 250], ['Beef stew with kisra', 'Lunch', 400], ['Goat stew with asida', 'Lunch', 450],
            ['Grilled tilapia with chips', 'Lunch', 600], ['Mixed vegetables and rice', 'Lunch', 250],
            ['Chapati and eggs', 'Breakfast', 150], ['Tea and mandazi', 'Breakfast', 100],
        ] as [$name, $section, $price]) {
            $dishes[] = MenuItem::query()->create(['kind' => MenuItem::DISH, 'name' => $name, 'section' => $section, 'unit' => 'plate', 'price_minor' => $price, 'active' => true]);
        }

        $stock = app(Stock::class);
        $manager = $this->team['restaurant_manager'];
        $drinks = [];
        foreach ([
            ['Bottled water 500 ml', 50, 400, 25, 'DRK-001'], ['Soda 300 ml', 80, 300, 40, 'DRK-002'],
            ['Juice 350 ml', 120, 200, 70, 'DRK-003'], ['Malt drink 330 ml', 150, 110, 90, 'DRK-004'],
        ] as [$name, $price, $opening, $cost, $barcode]) {
            $drink = MenuItem::query()->create(['kind' => MenuItem::DRINK, 'name' => $name, 'section' => 'Drinks', 'unit' => 'bottle', 'price_minor' => $price, 'barcode' => $barcode, 'reorder_level' => 40, 'active' => true]);
            $stock->opening($drink, $this->branch, $opening, $cost, $manager, 'Opening stock');
            $drinks[] = $drink;
        }

        // Three working weeks of lunches.
        $tills = app(Tills::class);
        $cashier = $this->team['shop_cashier'];
        for ($n = 0, $day = CarbonImmutable::parse('2026-10-12'); $day->lt('2026-11-04'); $day = $day->addDay()) {
            if ($day->isWeekend()) {
                continue;
            }

            $this->clock($day->setTime(7, 45)->toDateTimeString());
            $till = $tills->open($cashier, $this->branch, 5000);

            for ($sitting = 0; $sitting < 6; $sitting++, $n++) {
                $this->clock($day->setTime(12, 5 + $sitting * 9)->toDateTimeString());
                $table = $tables[$n % count($tables)];
                $guests = 1 + $n % 4;
                $lines = [
                    ['item_id' => $dishes[$n % 5]->id, 'quantity' => $guests],
                    ['item_id' => $drinks[$n % 4]->id, 'quantity' => $guests],
                ];
                $method = match ($n % 5) {
                    3 => PaymentMethod::MobileMoney,
                    4 => PaymentMethod::Card,
                    default => PaymentMethod::Cash,
                };
                $this->sitDown($table->id, $guests, $lines, $method, $day->setTime(12, 30 + $sitting * 4));
            }

            $this->clock($day->setTime(14, 40)->toDateTimeString());
            $order = app(Orders::class)->open($this->team['waiter'], $this->branch, Order::TAKEAWAY, null, null, 'Plant night shift');
            app(Orders::class)->add($order, $this->team['waiter'], [['item_id' => $dishes[1]->id, 'quantity' => 4], ['item_id' => $drinks[0]->id, 'quantity' => 4]]);
            $this->settle($order, PaymentMethod::Cash);

            $this->clock($day->setTime(16, 0)->toDateTimeString());
            $expected = $tills->summary($till->fresh())['expectedCashMinor'];
            $short = $day->isSameDay('2026-10-22');
            $tills->close($till->fresh(), $cashier, $short ? $expected - 300 : $expected, $short ? 'Short by 3.00, change given in error' : null);
        }

        // Today, midday: a till open, tables at each stage.
        $orders = app(Orders::class);
        $waiter = $this->team['waiter'];
        $this->clock('2026-11-04 07:45');
        $tills->open($cashier, $this->branch, 5000);

        $this->clock('2026-11-04 12:00');
        $this->sitDown($tables[0]->id, 2, [['item_id' => $dishes[1]->id, 'quantity' => 2], ['item_id' => $drinks[1]->id, 'quantity' => 2]], PaymentMethod::Cash, CarbonImmutable::parse('2026-11-04 12:25'));

        $this->clock('2026-11-04 12:30');
        $served = $orders->open($waiter, $this->branch, Order::DINE_IN, $tables[1]->id, 3);
        $orders->add($served, $waiter, [['item_id' => $dishes[3]->id, 'quantity' => 3], ['item_id' => $drinks[0]->id, 'quantity' => 3]]);
        $ticket = $orders->send($served->fresh(), $waiter);
        $orders->ready($ticket);
        $orders->served($ticket->fresh());

        $this->clock('2026-11-04 12:40');
        $ready = $orders->open($waiter, $this->branch, Order::DINE_IN, $tables[2]->id, 4);
        $orders->add($ready, $waiter, [['item_id' => $dishes[2]->id, 'quantity' => 4, 'note' => 'One without chilli']]);
        $orders->ready($orders->send($ready->fresh(), $waiter));

        $this->clock('2026-11-04 12:50');
        $cooking = $orders->open($waiter, $this->branch, Order::DINE_IN, $tables[4]->id, 2);
        $orders->add($cooking, $waiter, [['item_id' => $dishes[0]->id, 'quantity' => 2]]);
        $orders->send($cooking->fresh(), $waiter);

        $this->clock('2026-11-04 12:55');
        $notSent = $orders->open($waiter, $this->branch, Order::DINE_IN, $tables[5]->id, 1);
        $orders->add($notSent, $waiter, [['item_id' => $dishes[4]->id, 'quantity' => 1]]);

        $this->clock(null);
    }

    /**
     * @param  list<array{item_id: string, quantity: int, note?: string}>  $lines
     */
    private function sitDown(string $tableId, int $guests, array $lines, PaymentMethod $method, CarbonImmutable $paidAt): void
    {
        $orders = app(Orders::class);
        $order = $orders->open($this->team['waiter'], $this->branch, Order::DINE_IN, $tableId, $guests);
        $orders->add($order, $this->team['waiter'], $lines);
        $this->clock($paidAt->toDateTimeString());
        $this->settle($order, $method);
    }

    private function settle(Order $order, PaymentMethod $method): void
    {
        $orders = app(Orders::class);
        $ticket = $orders->send($order->fresh(), $this->team['waiter']);
        $orders->ready($ticket);
        $orders->served($ticket->fresh());

        $orders->pay($order->fresh(), $this->team['shop_cashier'], $method, null, $method === PaymentMethod::Cash ? null : 'REF-'.substr((string) $order->number, -5));
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
