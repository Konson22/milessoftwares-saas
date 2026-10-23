<?php

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Inventory\Actions\Stock;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Pharmacy\Models\Medicine;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/*
| The web pages for the desktop app (App\Support\AppPages): the same routes
| at api/v1/app/<web path>, signed in with the app's token and the branch
| from X-Branch-Id, the page's props as JSON and actions as message and
| where to go next.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-22 09:00'));
    $this->juba = $this->platform()->branch('acme', 'Juba');
    $this->wau = $this->platform()->branch('acme', 'Wau');
    $this->asTenant('acme', fn () => Workspace::query()->create(['slug' => 'acme', 'display_name' => 'Acme Pharmacy', 'currency' => 'SSP', 'timezone' => 'Africa/Juba']));

    $this->para = $this->asTenant('acme', fn () => Medicine::factory()->create(['name' => 'Panadol', 'sell_price_minor' => 250]));
    $keeper = User::factory()->create();
    $this->batch = $this->asTenant('acme', fn () => app(Stock::class)->add($this->para->item, $this->juba, 'P-1', CarbonImmutable::parse('2027-12-31'), 20, 100, $keeper));
});

function appPage(string $method, string $path, array $data = [], ?string $branch = null, ?string $from = null)
{
    $request = test()->withHeader('X-Branch-Id', $branch ?? test()->juba);
    if ($from !== null) {
        $request = $request->withHeader('X-App-Page', $from);
    }

    return $request->json($method, test()->workspaceUrl('acme', 'api/v1/app/'.$path), $data);
}

it('answers a page as its component and props, with the shared ones', function () {
    $this->actingAsAppMember('acme', ['pharmacy.stock.view', 'pharmacy.stock.adjust'], branch: $this->juba);

    appPage('GET', 'stock')
        ->assertOk()
        ->assertJsonPath('component', 'stock/index')
        ->assertJsonPath('url', 'stock')
        ->assertJsonPath('props.rows.0.label', $this->para->label())
        ->assertJsonPath('props.rows.0.sellable', 20)
        ->assertJsonPath('props.branch.name', 'Juba')
        ->assertJsonPath('props.branchPermissions', ['pharmacy.stock.view', 'pharmacy.stock.adjust']);

    appPage('GET', "stock/{$this->para->id}?x=1")
        ->assertOk()
        ->assertJsonPath('component', 'stock/show')
        ->assertJsonPath('url', "stock/{$this->para->id}?x=1")
        ->assertJsonPath('props.batches.0.batchNumber', 'P-1')
        ->assertJsonPath('props.canAdjust', true);
});

it('does what the web does, answering the message and staying on the page acted from', function () {
    $this->actingAsAppMember('acme', ['pharmacy.stock.view', 'pharmacy.stock.adjust'], branch: $this->juba);

    appPage('POST', "batches/{$this->batch->id}/adjustments", ['change' => 5, 'reason' => 'count_correction'], from: "stock/{$this->para->id}")
        ->assertOk()
        ->assertJson(['message' => 'Batch P-1 adjusted.', 'redirect' => null]);

    expect($this->asTenant('acme', fn () => Batch::query()->find($this->batch->id)->quantity_on_hand))->toBe(25);

    // Validation as the web's, as JSON.
    appPage('POST', "batches/{$this->batch->id}/adjustments", ['change' => -1, 'reason' => 'damaged'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('change');
});

// What pharmacy-electron-app's native screens (src/renderer/src/screens) read.
it('gives the desktop screens the props they draw', function () {
    $this->actingAsAppMember('acme', [
        'pharmacy.sales.view', 'pharmacy.stock.view', 'pharmacy.stock.adjust', 'pharmacy.prescriptions.view',
        'pharmacy.medicines.view', 'pharmacy.medicines.manage', 'pharmacy.purchasing.view', 'pharmacy.purchasing.manage',
    ], branch: $this->juba);

    appPage('GET', '')->assertOk()->assertJsonPath('component', 'home')->assertJsonStructure(['props' => [
        'sales' => ['count', 'totalMinor'], 'stock' => ['low', 'expiring'], 'awaitingVerification', 'expiringDays',
    ]]);

    appPage('GET', 'stock')->assertOk()->assertJsonStructure(['props' => [
        'rows' => [['id', 'label', 'unit', 'sellable', 'expired', 'held', 'nextExpiry', 'low', 'expiringSoon', 'reorderLevel']],
        'filters' => ['search', 'filter'], 'expiringDays',
    ]]);

    appPage('GET', "stock/{$this->para->id}")->assertOk()->assertJsonStructure(['props' => [
        'item' => ['id', 'label', 'unit', 'controlled', 'reorderLevel', 'tracksExpiry'],
        'batches' => [['id', 'batchNumber', 'expiresOn', 'expired', 'quantity', 'unitCostMinor', 'status', 'holdReason', 'locations', 'notPutAway']],
        'movements' => [['id', 'at', 'type', 'quantity', 'batchNumber', 'reason', 'note', 'by']],
        'pendingDisposals', 'locations', 'reasons' => [['value', 'label']], 'disposalReasons' => [['value', 'label']], 'canAdjust', 'canHold',
    ]]);

    appPage('GET', 'medicines')->assertOk()->assertJsonPath('component', 'medicines/index')->assertJsonStructure(['props' => [
        'medicines' => [['id', 'name', 'genericName', 'form', 'strength', 'unit', 'category', 'storageLabel', 'prescriptionOnly', 'controlled', 'sellPriceMinor', 'lastUnitCostMinor', 'active']],
        'filters' => ['search', 'category', 'inactive'], 'categories', 'canManage',
    ]]);

    appPage('GET', "medicines/{$this->para->id}/edit")->assertOk()->assertJsonPath('component', 'medicines/form')->assertJsonStructure(['props' => [
        'medicine' => ['id', 'name', 'genericName', 'form', 'strength', 'unit', 'barcode', 'category', 'storage', 'prescriptionOnly', 'controlled', 'sellPrice', 'lastUnitCostMinor', 'reorderLevel', 'active', 'packs'],
        'forms' => [['value', 'label']], 'storageConditions' => [['value', 'label']], 'categories',
    ]]);

    appPage('GET', 'suppliers')->assertOk()->assertJsonPath('component', 'suppliers/index')->assertJsonStructure(['props' => ['suppliers', 'canManage']]);
});

it('names the page to go to next', function () {
    $this->actingAsAppMember('acme', ['pharmacy.medicines.view', 'pharmacy.medicines.manage']);

    appPage('POST', 'medicines', [
        'name' => 'Brufen', 'generic_name' => 'ibuprofen', 'form' => 'tablet', 'unit' => 'tablet', 'sell_price' => '2.80', 'reorder_level' => 10,
    ], from: 'medicines/create')
        ->assertOk()
        ->assertJsonPath('redirect', 'medicines')
        ->assertJsonPath('message', fn (string $message) => str_starts_with($message, 'Brufen') && str_ends_with($message, 'added.'));
});

it('keeps the web\'s permissions, sign-in and branch', function () {
    $this->withHeader('X-Branch-Id', $this->juba)->getJson($this->workspaceUrl('acme', 'api/v1/app/stock'))->assertUnauthorized();

    $this->actingAsAppMember('acme', ['pharmacy.sales.view'], branch: $this->juba);
    appPage('GET', 'stock')->assertForbidden();
    appPage('GET', 'medicines')->assertForbidden();

    $this->actingAsAppMember('acme', ['pharmacy.stock.view'], branch: $this->juba);
    appPage('GET', 'stock', branch: $this->wau)->assertStatus(409);
    $this->getJson($this->workspaceUrl('acme', 'api/v1/app/stock'))->assertStatus(409)->assertJsonPath('code', 'branch_required');

    // Another branch's batch is unknown, as on the web.
    $wau = $this->asTenant('acme', fn () => app(Stock::class)->add($this->para->item, $this->wau, 'W-1', CarbonImmutable::parse('2027-12-31'), 5, 100, User::factory()->create()));
    $this->actingAsAppMember('acme', ['pharmacy.stock.view', 'pharmacy.stock.adjust'], branch: $this->juba);
    appPage('POST', "batches/{$wau->id}/adjustments", ['change' => 1, 'reason' => 'count_correction'])->assertNotFound();
});

it('leaves the web pages as they are', function () {
    $this->actingAsMember('acme', ['pharmacy.stock.view']);
    $this->put($this->workspaceUrl('acme', 'branch'), ['branch_id' => $this->juba]);

    $this->get($this->workspaceUrl('acme', 'stock'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('stock/index'));
});
