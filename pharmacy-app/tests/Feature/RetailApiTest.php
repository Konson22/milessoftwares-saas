<?php

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Inventory\Actions\Stock;
use App\Modules\Pharmacy\Models\Medicine;
use App\Modules\Retail\Models\Product;
use App\Modules\Sales\Actions\Serials;
use App\Modules\Sales\Models\ItemSerial;
use App\Modules\Sales\Models\Sale;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Miles\PlatformClient\PlatformConfig;

/*
| The counter API for the Retail module, at {workspace}/retail/api/v1: the
| desktop and mobile counter apps sell for a shop as they do for the
| pharmacy, with the retail application's own client and permissions. The
| shop's web pages are there for the apps too (AppPages).
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-24 09:00'));
    $this->juba = $this->platform()->branch('acme', 'Juba');
    $this->asTenant('acme', fn () => Workspace::query()->create(['slug' => 'acme', 'display_name' => 'Acme', 'currency' => 'SSP', 'timezone' => 'Africa/Juba']));

    $keeper = User::factory()->create();
    $this->asTenant('acme', function () use ($keeper) {
        $this->soap = Product::factory()->create(['name' => 'Soap', 'barcode' => '6009', 'sell_price_minor' => 300]);
        $this->para = Medicine::factory()->create(['name' => 'Panadol', 'barcode' => '6001']);
        app(Stock::class)->add($this->soap, $this->juba, 'LOT-S', null, 12, 200, $keeper);
        app(Stock::class)->add($this->para->item, $this->juba, 'P-1', CarbonImmutable::parse('2027-12-31'), 10, 90, $keeper);
    });
});

function shopApi(string $method, string $path, array $data = [])
{
    return test()->withHeader('X-Branch-Id', test()->juba)
        ->json($method, test()->workspaceUrl('acme', 'api/v1/'.$path, 'retail'), $data);
}

function shopAppSeller(array $permissions = ['retail.sales.create']): User
{
    $user = test()->actingAsAppMember('acme', $permissions, branch: test()->juba, application: 'retail');
    assert($user instanceof User);
    test()->openTill($user, test()->juba);

    return $user;
}

it('offers the shop its products, by search and by barcode', function () {
    shopAppSeller();

    shopApi('GET', 'items?search=')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Soap')
        ->assertJsonPath('data.0.price_minor', 300)
        ->assertJsonPath('data.0.available', 12)
        ->assertJsonPath('data.0.tracks_serials', false);
    shopApi('GET', 'items/lookup?code=6009')->assertOk()->assertJsonPath('data.id', $this->soap->id);
    shopApi('GET', 'items/lookup?code=6001')->assertNotFound();
});

it('sells for the shop, as a retail sale', function () {
    shopAppSeller();

    shopApi('POST', 'sales', [
        'submission_id' => (string) Str::uuid(),
        'items' => [['item_id' => $this->soap->id, 'quantity' => 2]],
        'payment_method' => 'cash',
        'amount_tendered_minor' => 1000,
    ])->assertCreated();

    $sale = $this->asTenant('acme', fn () => Sale::query()->sole());
    expect($sale->application)->toBe('retail')->and($sale->total_minor)->toBe(600);

    // A medicine is not sold at the shop.
    shopApi('POST', 'sales', [
        'submission_id' => (string) Str::uuid(),
        'items' => [['item_id' => $this->para->id, 'quantity' => 1]],
        'payment_method' => 'cash',
        'amount_tendered_minor' => 1000,
    ])->assertUnprocessable();
});

it('takes only the retail app\'s tokens and retail permissions', function () {
    // Without a mobile client of its own, the shop has no API.
    config(['platform-client.hosted.retail.mobile.client_id' => null]);
    $this->actingAsAppMember('acme', ['retail.sales.create'], branch: $this->juba);
    shopApi('GET', 'items')->assertNotFound();

    // The pharmacy app's token is for another client.
    config(['platform-client.hosted.retail.mobile.client_id' => 'retail-mobile-client']);
    shopApi('GET', 'items')->assertUnauthorized();

    // Pharmacy permissions do not sell at the shop.
    $user = $this->actingAsAppMember('acme', ['pharmacy.sales.create'], branch: $this->juba, application: 'retail');
    shopApi('GET', 'items')->assertForbidden();

    // Prescriptions are the pharmacy's only.
    shopAppSeller();
    shopApi('GET', 'prescriptions')->assertNotFound();
});

it('answers the shop\'s web pages for the desktop app', function () {
    $this->actingAsAppMember('acme', ['retail.stock.view'], branch: $this->juba, application: 'retail');

    shopApi('GET', 'app/stock')->assertOk()
        ->assertJsonPath('component', 'retail/stock/index')
        ->assertJsonPath('url', 'stock')
        ->assertJsonPath('props.rows.0.label', 'Soap');
});

it('sells and takes back products by their serial numbers', function () {
    $user = shopAppSeller(['retail.sales.create', 'retail.sales.view', 'retail.sales.void']);
    $this->asTenant('acme', function () use ($user) {
        $this->phone = Product::factory()->create(['name' => 'Phone', 'sell_price_minor' => 100_000, 'tracks_serials' => true]);
        app(Stock::class)->add($this->phone, $this->juba, 'LOT-P', null, 2, 60_000, $user);
        // As the shop records them (the industry is the request's).
        app(PlatformConfig::class)->runAs('retail', fn () => app(Serials::class)->record($this->phone, ['IMEI-1', 'IMEI-2'], $user));
    });

    shopApi('GET', 'items?search=Phone')->assertOk()->assertJsonPath('data.0.tracks_serials', true);

    $sale = shopApi('POST', 'sales', [
        'submission_id' => (string) Str::uuid(),
        'items' => [['item_id' => $this->phone->id, 'quantity' => 2, 'serials' => ['IMEI-1', 'IMEI-2']]],
        'payment_method' => 'cash',
        'amount_tendered_minor' => 200_000,
    ])->assertCreated()->json('data.id');

    $line = shopApi('GET', "sales/{$sale}")->assertOk()
        ->assertJsonPath('data.lines.0.tracks_serials', true)
        ->assertJsonPath('data.lines.0.serials', ['IMEI-1', 'IMEI-2'])
        ->json('data.lines.0.id');

    // Each unit back names its serial.
    shopApi('POST', "sales/{$sale}/returns", ['reason' => 'Faulty', 'lines' => [['sale_line_id' => $line, 'quantity' => 1, 'restock' => true]]])
        ->assertUnprocessable()->assertJsonValidationErrors('lines.0.serials');
    shopApi('POST', "sales/{$sale}/returns", ['reason' => 'Faulty', 'lines' => [['sale_line_id' => $line, 'quantity' => 1, 'restock' => true, 'serials' => ['IMEI-2']]]])
        ->assertCreated();

    expect($this->asTenant('acme', fn () => ItemSerial::query()->where('serial', 'IMEI-2')->value('status')))->toBe(ItemSerial::IN_STOCK);
});
