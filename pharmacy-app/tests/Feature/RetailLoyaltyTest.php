<?php

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Inventory\Actions\Stock;
use App\Modules\Retail\Models\Product;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\LoyaltyMovement;
use App\Modules\Sales\Models\Sale;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Loyalty: a customer named at the till earns points on what they pay and
| spends them there as a discount, shared over the lines. A void undoes
| both; a return takes back the points its part earned.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-24 10:00'));
    $this->juba = $this->platform()->branch('acme', 'Juba');
    $this->asTenant('acme', fn () => Workspace::query()->create(['slug' => 'acme', 'display_name' => 'Acme', 'currency' => 'SSP', 'timezone' => 'Africa/Juba']));

    $keeper = User::factory()->create();
    $this->asTenant('acme', function () use ($keeper) {
        $this->soap = Product::factory()->create(['name' => 'Soap', 'sell_price_minor' => 1000]);
        $this->rice = Product::factory()->create(['name' => 'Rice', 'sell_price_minor' => 3000]);
        app(Stock::class)->add($this->soap, $this->juba, 'LOT-S', null, 50, 500, $keeper);
        app(Stock::class)->add($this->rice, $this->juba, 'LOT-R', null, 50, 1500, $keeper);
        $this->ann = Customer::factory()->create(['name' => 'Ann']);
    });

    // 10.00 spent earns a point; a point takes 0.50 off.
    $this->actingAsMember('acme', ['retail.prices.manage'], application: 'retail');
    $this->put(loyaltyUrl('loyalty'), ['active' => '1', 'spend_per_point' => '10.00', 'point_value' => '0.50'])->assertSessionHasNoErrors();
});

function loyaltyUrl(string $path = ''): string
{
    return test()->workspaceUrl('acme', $path, 'retail');
}

function loyalSale(array $items, array $extra = [], array $permissions = ['retail.sales.create', 'retail.sales.view'])
{
    $user = test()->actingAsMember('acme', $permissions, branch: test()->juba, application: 'retail');
    test()->openTill($user, test()->juba);

    return test()->post(loyaltyUrl('sales'), $extra + [
        'submission_id' => (string) Str::uuid(),
        'items' => $items,
        'payment_method' => 'cash',
    ]);
}

function annPoints(): int
{
    return test()->asTenant('acme', fn () => (int) test()->ann->fresh()->loyalty_points);
}

it('earns points on a sale naming the customer, and shows them at the till', function () {
    loyalSale([['item_id' => $this->soap->id, 'quantity' => 2], ['item_id' => $this->rice->id, 'quantity' => 2]], ['customer_id' => $this->ann->id])
        ->assertSessionHasNoErrors();
    expect(annPoints())->toBe(8);

    // A sale naming nobody earns nothing.
    loyalSale([['item_id' => $this->soap->id, 'quantity' => 5]])->assertSessionHasNoErrors();
    expect($this->asTenant('acme', fn () => LoyaltyMovement::query()->count()))->toBe(1);

    $user = $this->actingAsMember('acme', ['retail.sales.create'], branch: $this->juba, application: 'retail');
    $this->openTill($user, $this->juba);
    $this->get(loyaltyUrl('sales/create'))->assertInertia(fn (Assert $page) => $page
        ->where('loyalty.pointValueMinor', 50)
        ->where('loyalty.members.0.name', 'Ann')
        ->where('loyalty.members.0.points', 8));
});

it('redeems points as a discount shared over the lines, earning on what was paid', function () {
    $this->asTenant('acme', fn () => $this->ann->forceFill(['loyalty_points' => 30])->save());

    // 40.00 less 20 points (10.00) = 30.00 paid, which earns 3.
    loyalSale([['item_id' => $this->soap->id, 'quantity' => 1], ['item_id' => $this->rice->id, 'quantity' => 1]], ['customer_id' => $this->ann->id, 'redeem_points' => 20])
        ->assertSessionHasNoErrors();

    $sale = $this->asTenant('acme', fn () => Sale::query()->with('lines')->sole());
    expect($sale->total_minor)->toBe(3000)
        ->and($sale->discount_minor)->toBe(1000)
        ->and($sale->lines->sum('line_total_minor'))->toBe(3000)
        ->and($sale->lines->firstWhere('item_id', $this->soap->id)->discount_minor)->toBe(250)
        ->and($sale->lines->firstWhere('item_id', $this->rice->id)->discount_minor)->toBe(750)
        ->and(annPoints())->toBe(30 - 20 + 3);

    // Finance books what was charged.
    $invoice = json_decode(DB::table('platform_outbox')->where('event_type', 'finance.invoice.issued')->value('payload'), true);
    expect($invoice['total_minor'])->toBe(3000)
        ->and(array_sum(array_column($invoice['lines'], 'amount_minor')))->toBe(3000);
});

it('never redeems more points than held, or than the sale is worth', function () {
    $this->asTenant('acme', fn () => $this->ann->forceFill(['loyalty_points' => 30])->save());

    loyalSale([['item_id' => $this->rice->id, 'quantity' => 1]], ['customer_id' => $this->ann->id, 'redeem_points' => 31])->assertSessionHasErrors('redeem_points');
    // A 10.00 sale is worth only 20 points; and points need a customer.
    loyalSale([['item_id' => $this->soap->id, 'quantity' => 1]], ['customer_id' => $this->ann->id, 'redeem_points' => 21])->assertSessionHasErrors('redeem_points');
    loyalSale([['item_id' => $this->soap->id, 'quantity' => 1]], ['redeem_points' => 5])->assertSessionHasErrors('redeem_points');

    expect($this->asTenant('acme', fn () => Sale::query()->count()))->toBe(0)
        ->and(annPoints())->toBe(30);
});

it('undoes the points of a voided sale', function () {
    $this->asTenant('acme', fn () => $this->ann->forceFill(['loyalty_points' => 10])->save());
    loyalSale([['item_id' => $this->rice->id, 'quantity' => 2]], ['customer_id' => $this->ann->id, 'redeem_points' => 10])->assertSessionHasNoErrors();
    expect(annPoints())->toBe(0 + 5);
    $sale = $this->asTenant('acme', fn () => Sale::query()->sole());

    $user = $this->actingAsMember('acme', ['retail.sales.view', 'retail.sales.void'], branch: $this->juba, application: 'retail');
    $this->openTill($user, $this->juba);
    $this->post(loyaltyUrl("sales/{$sale->id}/void"), ['reason' => 'Rang up wrong'])->assertSessionHasNoErrors();

    expect(annPoints())->toBe(10);
});

it('takes back the points a returned part earned, in parts adding up', function () {
    loyalSale([['item_id' => $this->soap->id, 'quantity' => 3]], ['customer_id' => $this->ann->id])->assertSessionHasNoErrors();
    expect(annPoints())->toBe(3);
    $sale = $this->asTenant('acme', fn () => Sale::query()->with('lines')->sole());
    $line = $sale->lines->sole();

    $user = $this->actingAsMember('acme', ['retail.sales.view', 'retail.sales.void'], branch: $this->juba, application: 'retail');
    $this->openTill($user, $this->juba);
    foreach ([1, 2] as $quantity) {
        $this->post(loyaltyUrl("sales/{$sale->id}/returns"), [
            'reason' => 'Changed their mind',
            'lines' => [['sale_line_id' => $line->id, 'quantity' => $quantity, 'restock' => '1']],
        ])->assertSessionHasNoErrors();
    }

    expect(annPoints())->toBe(0);
});

it('earns nothing while the program is paused, and only managers set it', function () {
    $this->put(loyaltyUrl('loyalty'), ['active' => '0', 'spend_per_point' => '10.00', 'point_value' => '0.50'])->assertSessionHasNoErrors();
    $this->get(loyaltyUrl('loyalty'))->assertInertia(fn (Assert $page) => $page
        ->component('retail/loyalty/edit')
        ->where('program.active', false)
        ->where('program.spendPerPoint', '10.00'));

    loyalSale([['item_id' => $this->rice->id, 'quantity' => 1]], ['customer_id' => $this->ann->id])->assertSessionHasNoErrors();
    expect(annPoints())->toBe(0);

    $this->actingAsMember('acme', ['retail.sales.create'], branch: $this->juba, application: 'retail');
    $this->put(loyaltyUrl('loyalty'), ['active' => '1', 'spend_per_point' => '1.00', 'point_value' => '5.00'])->assertForbidden();
});
