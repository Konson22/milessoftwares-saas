<?php

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Inventory\Actions\Stock;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Pharmacy\Models\Medicine;
use App\Modules\Retail\Models\Product;
use App\Modules\Sales\Models\Sale;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Miles\SharedModuleKit\Envelope;

/*
| Retail R1 (docs/erp/retail-roadmap.md): the retail application, served by
| this deployment at /retail, with its product catalogue and a barcode
| counter on the shared Inventory and Sales engine. Its members, items and
| sales never mix with the pharmacy's.
*/

beforeEach(function () {
    config(['platform-client.modules.finance' => 'https://finance.internal/events']);
    $this->travelTo(CarbonImmutable::parse('2026-10-19 10:00'));
    $this->juba = $this->platform()->branch('acme', 'Juba');
    $this->asTenant('acme', fn () => Workspace::query()->create(['slug' => 'acme', 'display_name' => 'Acme', 'currency' => 'SSP', 'timezone' => 'Africa/Juba']));

    $this->soap = $this->asTenant('acme', fn () => Product::factory()->create(['name' => 'Soap', 'barcode' => '6001234500017', 'sell_price_minor' => 300]));
    $this->para = $this->asTenant('acme', fn () => Medicine::factory()->create(['name' => 'Panadol', 'barcode' => '5000000000001']));
    $keeper = User::factory()->create();
    $this->asTenant('acme', function () use ($keeper) {
        app(Stock::class)->add($this->soap, $this->juba, 'LOT-1', null, 12, 200, $keeper);
        app(Stock::class)->add($this->para->item, $this->juba, 'P-1', CarbonImmutable::parse('2027-12-31'), 10, 90, $keeper);
    });
});

function retailUrl(string $path = ''): string
{
    return test()->workspaceUrl('acme', $path, 'retail');
}

/**
 * Signs in someone who sells in the shop, with their till open.
 */
function shopSeller(array $permissions = ['retail.sales.create', 'retail.sales.view']): User
{
    $user = test()->actingAsMember('acme', $permissions, branch: test()->juba, application: 'retail');
    test()->openTill($user, test()->juba);
    assert($user instanceof User);

    return $user;
}

describe('access', function () {
    it('keeps pharmacy members out of the shop, and shop members out of the pharmacy', function () {
        $this->actingAsMember('acme', ['pharmacy.sales.create', 'pharmacy.medicines.view'], branch: $this->juba);
        $this->get(retailUrl('products'))->assertForbidden();
        $this->get(retailUrl('sales/create'))->assertForbidden();

        $this->actingAsMember('acme', ['retail.sales.create', 'retail.products.view'], branch: $this->juba, application: 'retail');
        $this->get(retailUrl('products'))->assertOk();
        $this->get($this->workspaceUrl('acme', 'medicines'))->assertForbidden();
    });

    it('checks retail permissions on the shared screens', function () {
        $this->actingAsMember('acme', ['retail.sales.view'], branch: $this->juba, application: 'retail');

        $this->get(retailUrl('sales/create'))->assertForbidden();
        $this->get(retailUrl('stock'))->assertForbidden();
        $this->get(retailUrl('sales'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('retail/sales/index'));
    });

    it('sends a signed-out visitor to the retail sign-in', function () {
        $this->get(retailUrl('products'))->assertRedirect(retailUrl('auth/login'));
    });
});

describe('the product catalogue', function () {
    it('lists products only', function () {
        $this->actingAsMember('acme', ['retail.products.view'], application: 'retail');

        $this->get(retailUrl('products'))->assertInertia(fn (Assert $page) => $page
            ->component('retail/products/index')
            ->has('products', 1)
            ->where('products.0.name', 'Soap')
            ->where('products.0.tracksExpiry', false)
            ->where('products.0.lastUnitCostMinor', 200)
            ->where('canManage', false));
    });

    it('adds a product with its packs', function () {
        $this->actingAsMember('acme', ['retail.products.view', 'retail.products.manage'], application: 'retail');

        $this->post(retailUrl('products'), [
            'name' => ' Cola 500ml ',
            'unit' => 'bottle',
            'barcode' => '5449000000996',
            'category' => 'Beverages',
            'sell_price' => '1.50',
            'reorder_level' => 24,
            'packs' => [['name' => 'crate', 'units' => 24, 'sell_price' => '30.00']],
        ])->assertRedirect(retailUrl('products'))->assertSessionHasNoErrors();

        $cola = $this->asTenant('acme', fn () => Product::query()->where('barcode', '5449000000996')->with('packs')->sole());
        expect($cola->name)->toBe('Cola 500ml')
            ->and($cola->kind)->toBe('product')
            ->and($cola->tracks_expiry)->toBeFalse()
            ->and($cola->sell_price_minor)->toBe(150)
            ->and($cola->packs->map(fn ($pack) => [$pack->name, $pack->units, $pack->sell_price_minor])->all())->toBe([['crate', 24, 3000]]);
    });

    it('refuses a barcode another item already has, medicines included', function () {
        $this->actingAsMember('acme', ['retail.products.manage'], application: 'retail');

        $this->post(retailUrl('products'), ['name' => 'Copy', 'unit' => 'piece', 'barcode' => '5000000000001', 'sell_price' => '1.00', 'reorder_level' => 0])
            ->assertSessionHasErrors('barcode');
    });

    it('edits a product, but never a medicine', function () {
        $this->actingAsMember('acme', ['retail.products.manage'], application: 'retail');

        $this->get(retailUrl("products/{$this->soap->id}/edit"))->assertInertia(fn (Assert $page) => $page
            ->component('retail/products/form')
            ->where('product.hasStock', true));
        $this->get(retailUrl("products/{$this->para->id}/edit"))->assertNotFound();
        $this->put(retailUrl("products/{$this->para->id}"), ['name' => 'Taken', 'unit' => 'piece', 'sell_price' => '1.00', 'reorder_level' => 0])->assertNotFound();

        expect($this->asTenant('acme', fn () => $this->para->item->fresh()->name))->not->toBe('Taken');
    });

    it('keeps expiry tracking fixed once a product has stock', function () {
        $this->actingAsMember('acme', ['retail.products.manage'], application: 'retail');

        $this->put(retailUrl("products/{$this->soap->id}"), [
            'name' => 'Soap', 'unit' => 'piece', 'sell_price' => '3.00', 'reorder_level' => 5, 'tracks_expiry' => true,
        ])->assertSessionHasErrors('tracks_expiry');

        expect($this->asTenant('acme', fn () => $this->soap->fresh()->tracks_expiry))->toBeFalse();
    });
});

describe('the counter', function () {
    it('offers the shop its products and not the pharmacy its medicines', function () {
        shopSeller();

        $this->get(retailUrl('sales/create'))->assertInertia(fn (Assert $page) => $page
            ->component('retail/sales/create')
            ->has('items', 1)
            ->where('items.0.barcode', '6001234500017')
            ->where('items.0.available', 12));
    });

    it('sells a scanned product as a retail sale and books it in Finance as retail', function () {
        shopSeller();

        $response = $this->post(retailUrl('sales'), [
            'submission_id' => (string) Str::uuid(),
            'items' => [['item_id' => $this->soap->id, 'quantity' => 2]],
            'payment_method' => 'cash',
            'amount_tendered' => '10.00',
        ])->assertSessionHasNoErrors();

        $sale = $this->asTenant('acme', fn () => Sale::query()->sole());
        $response->assertRedirect(retailUrl("sales/{$sale->id}"));
        expect($sale->application)->toBe('retail')
            ->and($sale->total_minor)->toBe(600)
            ->and($this->asTenant('acme', fn () => (int) Batch::query()->where('item_id', $this->soap->id)->sum('quantity_on_hand')))->toBe(10);

        expect(DB::table('platform_outbox')->where('event_type', 'finance.invoice.issued')->value('application'))->toBe('retail');
        $invoice = Envelope::fromArray($this->platform()->moduleEvents['finance'][0]);
        expect($invoice->sourceApp)->toBe('retail')
            ->and($invoice->payload['total_minor'])->toBe(600);

        $this->get(retailUrl("sales/{$sale->id}"))->assertInertia(fn (Assert $page) => $page->component('retail/sales/show'));
    });

    it('refuses to sell a medicine at the shop counter', function () {
        shopSeller();

        $this->post(retailUrl('sales'), [
            'items' => [['item_id' => $this->para->id, 'quantity' => 1]],
            'payment_method' => 'cash',
            'amount_tendered' => '10.00',
        ])->assertSessionHasErrors();

        expect($this->asTenant('acme', fn () => Sale::query()->count()))->toBe(0);
    });

    it('keeps the shop sales and the pharmacy sales apart', function () {
        $seller = shopSeller(['retail.sales.create', 'retail.sales.view']);
        $this->post(retailUrl('sales'), [
            'items' => [['item_id' => $this->soap->id, 'quantity' => 1]],
            'payment_method' => 'cash',
            'amount_tendered' => '10.00',
        ])->assertSessionHasNoErrors();
        $sale = $this->asTenant('acme', fn () => Sale::query()->sole());

        $this->get(retailUrl('sales'))->assertInertia(fn (Assert $page) => $page->has('sales', 1));

        $this->actingAsMember('acme', ['pharmacy.sales.view'], branch: $this->juba, user: $seller);
        $this->get($this->workspaceUrl('acme', 'sales'))->assertInertia(fn (Assert $page) => $page->has('sales', 0));
        $this->get($this->workspaceUrl('acme', "sales/{$sale->id}"))->assertNotFound();
    });
});

describe('stock', function () {
    it('shows product stock without expiry and hides medicines', function () {
        $this->actingAsMember('acme', ['retail.stock.view', 'retail.stock.adjust'], branch: $this->juba, application: 'retail');

        $this->get(retailUrl('stock'))->assertInertia(fn (Assert $page) => $page
            ->component('retail/stock/index')
            ->has('rows', 1)
            ->where('rows.0.label', 'Soap')
            ->where('rows.0.sellable', 12));
        $this->get(retailUrl("stock/{$this->para->id}"))->assertNotFound();

        $this->post(retailUrl("stock/{$this->soap->id}/opening"), ['batch_number' => 'LOT-2', 'quantity' => 5, 'unit_cost' => '2.10'])
            ->assertSessionHasNoErrors();
        expect($this->asTenant('acme', fn () => Batch::query()->where('batch_number', 'LOT-2')->sole()->expires_on))->toBeNull();
    });
});
