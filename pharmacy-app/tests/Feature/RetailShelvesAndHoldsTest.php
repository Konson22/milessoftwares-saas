<?php

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Inventory\Actions\Locations;
use App\Modules\Inventory\Actions\Stock;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Pharmacy\Models\Medicine;
use App\Modules\Retail\Models\Product;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Retail after R2 (docs/erp/retail-roadmap.md): the shop's goods on the
| branch's shelves, and batches held back from sale, as the pharmacy has them.
| A shop's member never sees or touches the pharmacy's batches at the same
| branch, nor the other way round.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-23 10:00'));
    $this->juba = $this->platform()->branch('acme', 'Juba');
    $this->asTenant('acme', fn () => Workspace::query()->create(['slug' => 'acme', 'display_name' => 'Acme', 'currency' => 'SSP', 'timezone' => 'Africa/Juba']));

    $keeper = User::factory()->create();
    [$this->soap, $this->soapBatch, $this->paraBatch, $this->shelf] = $this->asTenant('acme', function () use ($keeper) {
        $soap = Product::factory()->create(['name' => 'Soap', 'sell_price_minor' => 300]);
        $para = Medicine::factory()->create(['name' => 'Panadol']);
        $shelf = app(Locations::class)->create($this->juba, 'Aisle 1');

        return [
            $soap,
            app(Stock::class)->add($soap, $this->juba, 'LOT-1', null, 12, 200, $keeper, location: $shelf),
            app(Stock::class)->add($para->item, $this->juba, 'P-1', CarbonImmutable::parse('2027-12-31'), 10, 90, $keeper, location: $shelf),
            $shelf,
        ];
    });
});

function shelfUrl(string $path = ''): string
{
    return test()->workspaceUrl('acme', $path, 'retail');
}

function shopKeeper(array $permissions = ['retail.stock.view', 'retail.stock.adjust', 'retail.stock.hold']): User
{
    $user = test()->actingAsMember('acme', $permissions, branch: test()->juba, application: 'retail');
    assert($user instanceof User);

    return $user;
}

it('shows the branch shelves with the shop goods on them only', function () {
    shopKeeper();

    $this->get(shelfUrl('locations'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('retail/locations/index')
        ->where('locations.0.name', 'Aisle 1')
        ->where('locations.0.units', 12)
        ->where('notPutAway', 0)
        ->where('canManage', true));

    $this->get(shelfUrl("locations/{$this->shelf->id}"))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('retail/locations/show')
        ->has('contents', 1)
        ->where('contents.0.item', 'Soap'));

    // The pharmacy at the same branch sees its own medicine on the shelf, not the soap.
    $this->actingAsMember('acme', ['pharmacy.stock.view'], branch: $this->juba);
    $this->get($this->workspaceUrl('acme', "locations/{$this->shelf->id}"))->assertInertia(fn (Assert $page) => $page
        ->component('locations/show')
        ->has('contents', 1)
        ->where('contents.0.item', 'Panadol 500 mg tablet'));
});

it('adds shelves and moves goods between them', function () {
    shopKeeper();

    $this->post(shelfUrl('locations'), ['name' => 'Back store'])->assertSessionHasNoErrors();
    $back = $this->asTenant('acme', fn () => StockLocation::query()->where('name', 'Back store')->sole());

    $this->post(shelfUrl("batches/{$this->soapBatch->id}/move"), ['from_location_id' => $this->shelf->id, 'to_location_id' => $back->id, 'quantity' => 5])
        ->assertSessionHasNoErrors();

    $this->get(shelfUrl("stock/{$this->soap->id}"))->assertInertia(fn (Assert $page) => $page
        ->component('retail/stock/show')
        ->where('item.label', 'Soap')
        ->where('batches.0.locations', [
            ['id' => $this->shelf->id, 'name' => 'Aisle 1', 'quantity' => 7],
            ['id' => $back->id, 'name' => 'Back store', 'quantity' => 5],
        ])
        ->where('canHold', true));
});

it('holds a batch back from the counter and releases it', function () {
    shopKeeper();

    $this->post(shelfUrl("batches/{$this->soapBatch->id}/hold"), ['status' => 'quarantined', 'reason' => 'Leaking bottles'])->assertSessionHasNoErrors();
    expect($this->soapBatch->fresh()->status)->toBe(Batch::QUARANTINED);

    // Not sellable while held.
    $this->get(shelfUrl('stock'))->assertInertia(fn (Assert $page) => $page->where('rows.0.sellable', 0)->where('rows.0.held', 12));

    $this->post(shelfUrl("batches/{$this->soapBatch->id}/release"))->assertSessionHasNoErrors();
    expect($this->soapBatch->fresh()->status)->toBe(Batch::AVAILABLE);
});

it('needs the hold permission to hold or release', function () {
    shopKeeper(['retail.stock.view', 'retail.stock.adjust']);

    $this->post(shelfUrl("batches/{$this->soapBatch->id}/hold"), ['status' => 'recalled', 'reason' => 'Recall'])->assertForbidden();
    $this->get(shelfUrl("stock/{$this->soap->id}"))->assertInertia(fn (Assert $page) => $page->where('canHold', false));
});

it('never lets the shop adjust, hold, move or write off a pharmacy batch at the same branch', function () {
    shopKeeper(['retail.stock.view', 'retail.stock.adjust', 'retail.stock.hold']);
    $batch = $this->paraBatch->id;

    $this->post(shelfUrl("batches/{$batch}/adjustments"), ['change' => -5, 'reason' => 'count_correction'])->assertNotFound();
    $this->post(shelfUrl("batches/{$batch}/hold"), ['status' => 'recalled', 'reason' => 'x'])->assertNotFound();
    $this->post(shelfUrl("batches/{$batch}/move"), ['from_location_id' => $this->shelf->id, 'quantity' => 1])->assertNotFound();
    $this->post(shelfUrl("batches/{$batch}/disposals"), ['quantity' => 1, 'reason' => 'damaged'])->assertNotFound();

    expect($this->paraBatch->fresh())->quantity_on_hand->toBe(10)->status->toBe(Batch::AVAILABLE);

    // And the pharmacy never touches the shop's.
    $this->actingAsMember('acme', ['pharmacy.stock.view', 'pharmacy.stock.adjust', 'pharmacy.stock.hold'], branch: $this->juba);
    $this->post($this->workspaceUrl('acme', "batches/{$this->soapBatch->id}/adjustments"), ['change' => -5, 'reason' => 'count_correction'])->assertNotFound();
    $this->post($this->workspaceUrl('acme', "batches/{$this->soapBatch->id}/hold"), ['status' => 'recalled', 'reason' => 'x'])->assertNotFound();
});
