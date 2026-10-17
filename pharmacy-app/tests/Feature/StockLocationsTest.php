<?php

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Inventory\Actions\Stock;
use App\Modules\Inventory\Enums\AdjustmentReason;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\BatchLocation;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Pharmacy\Models\Medicine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18 08:00'));
    $this->juba = $this->platform()->branch('acme', 'Juba');
    $this->wau = $this->platform()->branch('acme', 'Wau');
    $this->asTenant('acme', fn () => Workspace::query()->create(['slug' => 'acme', 'display_name' => 'Acme', 'currency' => 'SSP', 'timezone' => 'Africa/Juba']));

    $this->para = $this->asTenant('acme', fn () => Medicine::factory()->create(['sell_price_minor' => 250]));
    $this->batch = $this->asTenant('acme', fn () => app(Stock::class)->add($this->para->item, $this->juba, 'P-1', CarbonImmutable::parse('2027-06-30'), 30, 100, User::factory()->create()));

    $this->keeper = $this->actingAsMember('acme', ['pharmacy.stock.view', 'pharmacy.stock.adjust'], branch: $this->juba);
    $this->post($this->workspaceUrl('acme', 'locations'), ['name' => 'Shelf A'])->assertSessionHasNoErrors();
    $this->post($this->workspaceUrl('acme', 'locations'), ['name' => 'Store room'])->assertSessionHasNoErrors();
    [$this->shelf, $this->store] = $this->asTenant('acme', fn () => [
        StockLocation::query()->where('name', 'Shelf A')->sole(),
        StockLocation::query()->where('name', 'Store room')->sole(),
    ]);
});

function placed(string $locationId): int
{
    return test()->asTenant('acme', fn () => (int) BatchLocation::query()->where('stock_location_id', $locationId)->sum('quantity'));
}

function moveStock(array $data): TestResponse
{
    return test()->post(test()->workspaceUrl('acme', 'batches/'.test()->batch->id.'/move'), $data);
}

it('puts stock away and moves it between locations without changing what is on hand', function () {
    moveStock(['to_location_id' => $this->store->id, 'quantity' => 20])->assertSessionHasNoErrors();
    moveStock(['from_location_id' => $this->store->id, 'to_location_id' => $this->shelf->id, 'quantity' => 8])->assertSessionHasNoErrors();
    // Only 10 are not put away, 12 in the store room.
    moveStock(['to_location_id' => $this->shelf->id, 'quantity' => 11])->assertSessionHasErrors('quantity');
    moveStock(['from_location_id' => $this->store->id, 'to_location_id' => $this->shelf->id, 'quantity' => 13])->assertSessionHasErrors('quantity');
    moveStock(['from_location_id' => $this->shelf->id, 'to_location_id' => $this->shelf->id, 'quantity' => 1])->assertSessionHasErrors('to_location_id');

    expect(placed($this->store->id))->toBe(12)
        ->and(placed($this->shelf->id))->toBe(8)
        ->and($this->asTenant('acme', fn () => Batch::query()->sole()->quantity_on_hand))->toBe(30)
        ->and($this->asTenant('acme', fn () => DB::table('stock_location_moves')->count()))->toBe(2);

    $this->get($this->workspaceUrl('acme', "stock/{$this->para->id}"))->assertInertia(fn (Assert $page) => $page
        ->where('batches.0.locations', [
            ['id' => $this->shelf->id, 'name' => 'Shelf A', 'quantity' => 8],
            ['id' => $this->store->id, 'name' => 'Store room', 'quantity' => 12],
        ])
        ->where('batches.0.notPutAway', 10)
        ->has('locations', 2));
    $this->get($this->workspaceUrl('acme', 'locations'))->assertInertia(fn (Assert $page) => $page
        ->where('locations.0.units', 8)
        ->where('locations.1.units', 12)
        ->where('notPutAway', 10));
    $this->get($this->workspaceUrl('acme', "locations/{$this->store->id}"))->assertInertia(fn (Assert $page) => $page
        ->component('locations/show')
        ->where('contents.0.batchNumber', 'P-1')
        ->where('contents.0.quantity', 12));
});

it('takes stock that leaves from what is not put away first, then from the locations', function () {
    moveStock(['to_location_id' => $this->store->id, 'quantity' => 15])->assertSessionHasNoErrors();
    moveStock(['to_location_id' => $this->shelf->id, 'quantity' => 10])->assertSessionHasNoErrors();

    $cashier = $this->actingAsMember('acme', ['pharmacy.sales.view', 'pharmacy.sales.create'], branch: $this->juba);
    $this->openTill($cashier, $this->juba);
    // 5 not put away, then 3 from Shelf A (locations in name order).
    $this->post($this->workspaceUrl('acme', 'sales'), [
        'items' => [['item_id' => $this->para->id, 'quantity' => 8]],
        'payment_method' => 'mobile_money',
    ])->assertSessionHasNoErrors();

    expect(placed($this->shelf->id))->toBe(7)
        ->and(placed($this->store->id))->toBe(15);

    // A count short by 20 empties the shelf and takes 13 from the store room.
    $this->asTenant('acme', fn () => app(Stock::class)->adjust(Batch::query()->sole(), -20, AdjustmentReason::CountCorrection, null, $cashier));
    expect(placed($this->shelf->id))->toBe(0)
        ->and(placed($this->store->id))->toBe(2)
        ->and($this->asTenant('acme', fn () => Batch::query()->sole()->quantity_on_hand))->toBe(2);
});

it('adds opening stock straight to a location', function () {
    $this->post($this->workspaceUrl('acme', "stock/{$this->para->id}/opening"), [
        'batch_number' => 'P-9', 'expires_on' => '2027-09-30', 'quantity' => 12, 'unit_cost' => '1.00', 'location_id' => $this->shelf->id,
    ])->assertSessionHasNoErrors();

    expect(placed($this->shelf->id))->toBe(12);
});

it('keeps location names unique, and a location in use until it is empty', function () {
    $this->post($this->workspaceUrl('acme', 'locations'), ['name' => 'shelf a'])->assertSessionHasErrors('name');

    moveStock(['to_location_id' => $this->shelf->id, 'quantity' => 2])->assertSessionHasNoErrors();
    $this->put($this->workspaceUrl('acme', "locations/{$this->shelf->id}"), ['name' => 'Shelf A', 'active' => '0'])->assertSessionHasErrors('active');

    moveStock(['from_location_id' => $this->shelf->id, 'quantity' => 2])->assertSessionHasNoErrors();
    $this->put($this->workspaceUrl('acme', "locations/{$this->shelf->id}"), ['name' => 'Front shelf', 'active' => '0'])->assertSessionHasNoErrors();

    expect($this->shelf->refresh())->name->toBe('Front shelf')->active->toBeFalse();
    moveStock(['to_location_id' => $this->shelf->id, 'quantity' => 1])->assertSessionHasErrors('to_location_id');
});

it('keeps locations to their branch and their managers', function () {
    $wauShelf = $this->asTenant('acme', fn () => StockLocation::query()->create(['branch_id' => $this->wau, 'name' => 'Wau shelf']));
    moveStock(['to_location_id' => $wauShelf->id, 'quantity' => 1])->assertNotFound();
    $this->get($this->workspaceUrl('acme', "locations/{$wauShelf->id}"))->assertNotFound();

    $this->actingAsMember('acme', ['pharmacy.stock.view'], branch: $this->juba);
    $this->get($this->workspaceUrl('acme', 'locations'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('canManage', false));
    $this->post($this->workspaceUrl('acme', 'locations'), ['name' => 'Fridge'])->assertForbidden();
    moveStock(['to_location_id' => $this->shelf->id, 'quantity' => 1])->assertForbidden();
});
