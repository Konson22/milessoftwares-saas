<?php

use App\Models\Workspace;
use App\Modules\Inventory\Actions\ItemPacks;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\ItemPack;
use App\Modules\MasterData\Models\Supplier;
use App\Modules\Pharmacy\Models\Medicine;
use App\Modules\Procurement\Models\GoodsReceipt;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\SupplierReturn;
use Inertia\Testing\AssertableInertia;
use Miles\SharedModuleKit\Envelope;

/*
| Units and packaging: stock is counted in the item's unit, orders and
| deliveries can count packs.
*/

beforeEach(function () {
    config(['platform-client.modules.finance' => 'https://finance.internal/events']);
    $this->travelTo(now()->setDate(2026, 10, 12)->setTime(9, 0));
    $this->juba = $this->platform()->branch('acme', 'Juba');
    $this->asTenant('acme', fn () => Workspace::query()->create(['slug' => 'acme', 'display_name' => 'Acme', 'currency' => 'SSP', 'timezone' => 'Africa/Juba']));
    $this->supplier = $this->asTenant('acme', fn () => Supplier::query()->create(['name' => 'Juba Medical Supplies']));
    $this->amox = $this->asTenant('acme', fn () => Medicine::factory()->create(['name' => 'Amoxil', 'unit' => 'capsule']));
    $this->para = $this->asTenant('acme', fn () => Medicine::factory()->create(['name' => 'Panadol']));
    $this->box = $this->asTenant('acme', function () {
        app(ItemPacks::class)->sync($this->amox->item, [['name' => 'box', 'units' => 30]]);

        return ItemPack::query()->sole();
    });
});

function buyer(): void
{
    test()->actingAsMember('acme', ['pharmacy.purchasing.view', 'pharmacy.purchasing.manage', 'pharmacy.purchasing.receive', 'pharmacy.stock.view'], branch: test()->juba);
}

function deliver(?PurchaseOrder $order, array $lines)
{
    return test()->post(test()->workspaceUrl('acme', 'receipts'), [
        'supplier_id' => test()->supplier->id,
        'purchase_order_id' => $order?->id,
        'received_on' => '2026-10-12',
        'lines' => $lines,
    ]);
}

function medicineWithPacks(array $packs): array
{
    return [
        'name' => 'Cipro', 'generic_name' => 'ciprofloxacin', 'form' => 'tablet', 'strength' => '500 mg',
        'unit' => 'tablet', 'sell_price' => '1.00', 'reorder_level' => 10, 'packs' => $packs,
    ];
}

it('keeps the packs a medicine is bought in', function () {
    $this->actingAsMember('acme', ['pharmacy.medicines.view', 'pharmacy.medicines.manage']);

    $this->post($this->workspaceUrl('acme', 'medicines'), medicineWithPacks([['name' => 'Box', 'units' => 100], ['name' => 'strip', 'units' => 10]]))
        ->assertSessionHasNoErrors();
    $cipro = $this->asTenant('acme', fn () => Medicine::query()->where('name', 'Cipro')->sole());

    $this->get($this->workspaceUrl('acme', "medicines/{$cipro->id}/edit"))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('medicine.packs', [['name' => 'strip', 'units' => 10, 'sellPrice' => ''], ['name' => 'box', 'units' => 100, 'sellPrice' => '']]));

    // Matched by name: the strip changes size, the box goes, a blister arrives.
    $this->put($this->workspaceUrl('acme', "medicines/{$cipro->id}"), medicineWithPacks([['name' => 'strip', 'units' => 14], ['name' => 'blister', 'units' => 7]]))
        ->assertSessionHasNoErrors();

    expect($this->asTenant('acme', fn () => $cipro->item->packs()->pluck('units', 'name')->all()))->toBe(['blister' => 7, 'strip' => 14]);
});

it('refuses packs named like the unit, twice, or holding fewer than two', function (array $packs, string $error) {
    $this->actingAsMember('acme', ['pharmacy.medicines.view', 'pharmacy.medicines.manage']);

    $this->post($this->workspaceUrl('acme', 'medicines'), medicineWithPacks($packs))->assertSessionHasErrors($error);

    expect($this->asTenant('acme', fn () => Medicine::query()->where('name', 'Cipro')->exists()))->toBeFalse();
})->with([
    'the unit' => [[['name' => 'Tablet', 'units' => 10]], 'packs.0.name'],
    'twice' => [[['name' => 'box', 'units' => 10], ['name' => 'BOX ', 'units' => 20]], 'packs.1.name'],
    'one' => [[['name' => 'box', 'units' => 1]], 'packs.0.units'],
]);

it('orders boxes, receives boxes, and stocks capsules at the box cost shared out', function () {
    buyer();

    $this->post($this->workspaceUrl('acme', 'purchase-orders'), [
        'supplier_id' => $this->supplier->id,
        'lines' => [['item_id' => $this->amox->id, 'pack_id' => $this->box->id, 'quantity' => 4, 'unit_cost' => '30.00']],
    ])->assertSessionHasNoErrors();
    $order = $this->asTenant('acme', fn () => PurchaseOrder::query()->sole());
    $this->post($this->workspaceUrl('acme', "purchase-orders/{$order->id}/ordered"))->assertSessionHasNoErrors();
    $line = $order->lines()->sole();

    expect($line)->pack_name->toBe('box')->units_per_pack->toBe(30)->quantity_ordered->toBe(4)
        ->and($order->totalMinor())->toBe(4 * 3000);
    $this->get($this->workspaceUrl('acme', "purchase-orders/{$order->id}"))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('order.lines.0.pack', 'box of 30')->where('order.lines.0.ordered', 4));

    // The price went up: three boxes at 31.00.
    deliver($order, [['item_id' => $this->amox->id, 'purchase_order_line_id' => $line->id, 'batch_number' => 'AMX-1', 'expires_on' => '2027-09-30', 'quantity' => 3, 'unit_cost' => '31.00']])
        ->assertSessionHasNoErrors();

    expect($this->asTenant('acme', fn () => Batch::query()->sole()))
        ->quantity_on_hand->toBe(90)
        ->unit_cost_minor->toBe(103); // 3100 / 30, rounded

    $receipt = $this->asTenant('acme', fn () => GoodsReceipt::query()->sole());
    expect($receipt->total_minor)->toBe(9300)
        ->and($receipt->lines()->sole())->pack_name->toBe('box')->units_per_pack->toBe(30)->quantity->toBe(3);

    // Finance is billed exactly what the supplier charged.
    $bill = Envelope::fromArray($this->platform()->moduleEvents['finance'][0]);
    expect($bill->payload['total_minor'])->toBe(9300)
        ->and($bill->payload['lines'][0])->toMatchArray(['description' => 'Amoxil 500 mg tablet, box of 30 (batch AMX-1)', 'quantity' => 3, 'amount_minor' => 9300]);

    // One box is still expected, counted in boxes.
    $this->get($this->workspaceUrl('acme', "receipts/create?order={$order->id}"))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('order.lines.0.remaining', 1)->where('order.lines.0.pack', 'box of 30'));
    deliver($order, [['item_id' => $this->amox->id, 'purchase_order_line_id' => $line->id, 'batch_number' => 'AMX-2', 'expires_on' => '2027-09-30', 'quantity' => 2, 'unit_cost' => '30.00']])
        ->assertSessionHasErrors('lines.0.quantity');
});

it('receives packs without an order, but never another item\'s pack', function () {
    buyer();

    deliver(null, [['item_id' => $this->para->id, 'pack_id' => $this->box->id, 'batch_number' => 'P-1', 'expires_on' => '2027-09-30', 'quantity' => 1, 'unit_cost' => '5.00']])
        ->assertSessionHasErrors('lines.0.pack_id');
    expect($this->asTenant('acme', fn () => Batch::query()->exists()))->toBeFalse();

    deliver(null, [
        ['item_id' => $this->amox->id, 'pack_id' => $this->box->id, 'batch_number' => 'A-1', 'expires_on' => '2027-09-30', 'quantity' => 2, 'unit_cost' => '30.00'],
        ['item_id' => $this->para->id, 'batch_number' => 'P-1', 'expires_on' => '2027-09-30', 'quantity' => 12, 'unit_cost' => '0.50'],
    ])->assertSessionHasNoErrors();

    expect($this->asTenant('acme', fn () => Batch::query()->orderBy('batch_number')->pluck('quantity_on_hand', 'batch_number')->all()))
        ->toBe(['A-1' => 60, 'P-1' => 12]);
});

it('sends loose capsules back out of a box, credited out of its bill to the last minor unit', function () {
    buyer();
    // One box of 30 for 10.01: 33.37 a capsule.
    deliver(null, [['item_id' => $this->amox->id, 'pack_id' => $this->box->id, 'batch_number' => 'A-1', 'expires_on' => '2027-09-30', 'quantity' => 1, 'unit_cost' => '10.01']])
        ->assertSessionHasNoErrors();
    $receipt = $this->asTenant('acme', fn () => GoodsReceipt::query()->sole());
    $line = $receipt->lines()->sole();

    $this->get($this->workspaceUrl('acme', "receipts/{$receipt->id}/return"))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('receipt.lines.0.received', 30)->where('receipt.lines.0.returnable', 30)->where('receipt.lines.0.unit', 'capsule'));

    $back = fn (int $quantity) => $this->post($this->workspaceUrl('acme', "receipts/{$receipt->id}/returns"), [
        'returned_on' => '2026-10-12', 'reason' => 'Damaged', 'quantities' => [$line->id => $quantity],
    ]);

    // Three lots of 10 at 33.37 each would credit 10.02 for a 10.01 box.
    $back(10)->assertSessionHasNoErrors();
    $back(21)->assertSessionHasErrors("quantities.{$line->id}");
    $back(10)->assertSessionHasNoErrors();
    $back(10)->assertSessionHasNoErrors();

    expect($this->asTenant('acme', fn () => SupplierReturn::query()->orderBy('number')->pluck('total_minor')->all()))->toBe([334, 333, 334])
        ->and($this->asTenant('acme', fn () => Batch::query()->sole()->quantity_on_hand))->toBe(0);
    $this->get($this->workspaceUrl('acme', "receipts/{$receipt->id}"))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('receipt.lines.0.returned', 30)->where('canReturn', false));
});

it('shows what a unit last cost beside its selling price, and the pack sizes to split a pack cost by', function () {
    $this->actingAsMember('acme', ['pharmacy.purchasing.view', 'pharmacy.purchasing.manage', 'pharmacy.purchasing.receive', 'pharmacy.medicines.view', 'pharmacy.medicines.manage'], branch: $this->juba);

    // Never received: no buying price yet.
    $this->get($this->workspaceUrl('acme', 'medicines'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('medicines.0.name', 'Amoxil')->where('medicines.0.lastUnitCostMinor', null));

    // A box of 30 at 31.00: 103 a capsule, rounded.
    deliver(null, [['item_id' => $this->amox->id, 'pack_id' => $this->box->id, 'batch_number' => 'AMX-1', 'expires_on' => '2027-09-30', 'quantity' => 1, 'unit_cost' => '31.00']])
        ->assertSessionHasNoErrors();

    $this->get($this->workspaceUrl('acme', 'medicines'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('medicines.0.lastUnitCostMinor', 103));
    $this->get($this->workspaceUrl('acme', "medicines/{$this->amox->id}/edit"))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('medicine.lastUnitCostMinor', 103));
    $this->get($this->workspaceUrl('acme', 'purchase-orders/create'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('items.0.label', 'Amoxil 500 mg tablet')
            ->where('items.0.lastUnitCostMinor', 103)
            ->where('items.0.sellPriceMinor', $this->amox->sell_price_minor)
            ->where('items.0.packs.0.name', 'box')
            ->where('items.0.packs.0.units', 30));
});
