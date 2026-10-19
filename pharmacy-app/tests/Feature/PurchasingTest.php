<?php

use App\Models\Workspace;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\MasterData\Models\Supplier;
use App\Modules\Pharmacy\Models\Medicine;
use App\Modules\Procurement\Enums\PurchaseOrderStatus;
use App\Modules\Procurement\Models\GoodsReceipt;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\SupplierReturn;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Miles\SharedModuleKit\Envelope;

beforeEach(function () {
    $this->deliveries = 0;
    config(['platform-client.modules.finance' => 'https://finance.internal/events']);
    $this->travelTo(now()->setDate(2026, 10, 12)->setTime(9, 0));
    $this->juba = $this->platform()->branch('acme', 'Juba');
    $this->wau = $this->platform()->branch('acme', 'Wau');
    $this->asTenant('acme', fn () => Workspace::query()->create(['slug' => 'acme', 'display_name' => 'Acme', 'currency' => 'SSP', 'timezone' => 'Africa/Juba']));
    $this->supplier = $this->asTenant('acme', fn () => Supplier::query()->create(['name' => 'Juba Medical Supplies']));
    $this->amox = $this->asTenant('acme', fn () => Medicine::factory()->create(['name' => 'Amoxil']));
    $this->para = $this->asTenant('acme', fn () => Medicine::factory()->create(['name' => 'Panadol']));
});

function storekeeper(string $branch): void
{
    test()->actingAsMember('acme', ['pharmacy.purchasing.view', 'pharmacy.purchasing.manage', 'pharmacy.purchasing.receive', 'pharmacy.stock.view'], branch: $branch);
}

function orderAmoxAndPara(): PurchaseOrder
{
    test()->post(test()->workspaceUrl('acme', 'purchase-orders'), [
        'supplier_id' => test()->supplier->id,
        'lines' => [
            ['item_id' => test()->amox->id, 'quantity' => 100, 'unit_cost' => '1.20'],
            ['item_id' => test()->para->id, 'quantity' => 50, 'unit_cost' => '0.40'],
        ],
    ])->assertSessionHasNoErrors();

    $order = test()->asTenant('acme', fn () => PurchaseOrder::query()->orderByDesc('number')->firstOrFail());
    test()->post(test()->workspaceUrl('acme', "purchase-orders/{$order->id}/ordered"))->assertSessionHasNoErrors();

    return $order->refresh()->load('lines');
}

/**
 * Each delivery comes with its own invoice (INV-7781, INV-7782, …): the same
 * one is never received twice.
 */
function receiveLines(?PurchaseOrder $order, array $lines)
{
    test()->deliveries++;

    return test()->post(test()->workspaceUrl('acme', 'receipts'), [
        'supplier_id' => test()->supplier->id,
        'purchase_order_id' => $order?->id,
        'supplier_reference' => 'INV-'.(7780 + test()->deliveries),
        'received_on' => '2026-10-12',
        'lines' => $lines,
    ]);
}

it('drafts, sends and numbers purchase orders per organization', function () {
    storekeeper($this->juba);

    $first = orderAmoxAndPara();
    $second = orderAmoxAndPara();

    expect($first)->number->toBe('PO-00001')->status->toBe(PurchaseOrderStatus::Ordered)->branch_id->toBe($this->juba)
        ->and($second->number)->toBe('PO-00002')
        ->and($first->totalMinor())->toBe(100 * 120 + 50 * 40);
});

it('receives an order in two deliveries, into batches, and tells Finance once per delivery', function () {
    storekeeper($this->juba);
    $order = orderAmoxAndPara();
    [$amoxLine, $paraLine] = [$order->lines->firstWhere('item_id', $this->amox->id), $order->lines->firstWhere('item_id', $this->para->id)];

    receiveLines($order, [
        ['item_id' => $this->amox->id, 'purchase_order_line_id' => $amoxLine->id, 'batch_number' => 'AMX-1', 'expires_on' => '2027-09-30', 'quantity' => 60, 'unit_cost' => '1.20'],
    ])->assertSessionHasNoErrors();

    expect($order->refresh()->status)->toBe(PurchaseOrderStatus::PartlyReceived);

    receiveLines($order, [
        ['item_id' => $this->amox->id, 'purchase_order_line_id' => $amoxLine->id, 'batch_number' => 'AMX-2', 'expires_on' => '2028-01-31', 'quantity' => 40, 'unit_cost' => '1.25'],
        ['item_id' => $this->para->id, 'purchase_order_line_id' => $paraLine->id, 'batch_number' => 'PAR-1', 'expires_on' => '2027-12-31', 'quantity' => 50, 'unit_cost' => '0.40'],
    ])->assertSessionHasNoErrors();

    expect($order->refresh()->status)->toBe(PurchaseOrderStatus::Received);

    $batches = $this->asTenant('acme', fn () => Batch::query()->orderBy('batch_number')->get());
    expect($batches->pluck('quantity_on_hand', 'batch_number')->all())->toBe(['AMX-1' => 60, 'AMX-2' => 40, 'PAR-1' => 50])
        ->and($batches->pluck('branch_id')->unique()->all())->toBe([$this->juba]);

    $receipts = $this->asTenant('acme', fn () => GoodsReceipt::query()->orderBy('number')->get());
    expect($receipts->pluck('number')->all())->toBe(['GRN-00001', 'GRN-00002'])
        ->and($receipts[1]->total_minor)->toBe(40 * 125 + 50 * 40);

    // Finance got one bill per delivery, each a valid envelope.
    $events = $this->platform()->moduleEvents['finance'];
    expect($events)->toHaveCount(2);

    $envelope = Envelope::fromArray($events[1]);
    expect($envelope->eventType)->toBe('finance.bill.received')
        ->and($envelope->sourceApp)->toBe('pharmacy')
        ->and($envelope->sourceTransactionId)->toBe('goods_receipt:'.$receipts[1]->id)
        ->and($envelope->payload)->toMatchArray([
            'bill_number' => 'GRN-00002',
            'supplier_reference' => 'INV-7782',
            'supplier_name' => 'Juba Medical Supplies',
            'branch_id' => $this->juba,
            'currency' => 'SSP',
            'total_minor' => 7000,
            'received_on' => '2026-10-12',
        ])
        ->and($envelope->payload['lines'])->toHaveCount(2);

    // Every stock change points back at its receipt.
    $movement = $this->asTenant('acme', fn () => StockMovement::query()->where('reference_id', $receipts[0]->id)->sole());
    expect($movement)->reference_type->toBe('goods_receipt')->quantity->toBe(60);
});

it('refuses to receive more than the order still expects', function () {
    storekeeper($this->juba);
    $order = orderAmoxAndPara();
    $amoxLine = $order->lines->firstWhere('item_id', $this->amox->id);

    receiveLines($order, [
        ['item_id' => $this->amox->id, 'purchase_order_line_id' => $amoxLine->id, 'batch_number' => 'AMX-1', 'expires_on' => '2027-09-30', 'quantity' => 101, 'unit_cost' => '1.20'],
    ])->assertSessionHasErrors('lines.0.quantity');

    expect($this->asTenant('acme', fn () => Batch::query()->count()))->toBe(0);
});

it('keeps a delivery all or nothing: no stock, number or event when a line fails', function () {
    storekeeper($this->juba);
    receiveLines(null, [['item_id' => $this->amox->id, 'batch_number' => 'AMX-1', 'expires_on' => '2027-09-30', 'quantity' => 5, 'unit_cost' => '1.00']])->assertSessionHasNoErrors();

    // Second line repeats batch AMX-1 with another expiry: the whole receipt fails.
    receiveLines(null, [
        ['item_id' => $this->para->id, 'batch_number' => 'PAR-1', 'expires_on' => '2027-12-31', 'quantity' => 10, 'unit_cost' => '0.40'],
        ['item_id' => $this->amox->id, 'batch_number' => 'AMX-1', 'expires_on' => '2028-03-31', 'quantity' => 5, 'unit_cost' => '1.00'],
    ])->assertSessionHasErrors('expires_on');

    expect($this->asTenant('acme', fn () => Batch::query()->pluck('batch_number')->all()))->toBe(['AMX-1'])
        ->and($this->asTenant('acme', fn () => GoodsReceipt::query()->count()))->toBe(1)
        ->and(DB::table('platform_outbox')->count())->toBe(1);

    // The failed attempt did not use up GRN-00002.
    receiveLines(null, [['item_id' => $this->para->id, 'batch_number' => 'PAR-1', 'expires_on' => '2027-12-31', 'quantity' => 10, 'unit_cost' => '0.40']]);
    expect($this->asTenant('acme', fn () => GoodsReceipt::query()->latest('number')->value('number')))->toBe('GRN-00002');
});

it('treats another branch\'s order as unknown, and lines of other orders as not this order\'s', function () {
    storekeeper($this->wau);
    $wauOrder = orderAmoxAndPara();
    storekeeper($this->juba);

    $this->get($this->workspaceUrl('acme', "purchase-orders/{$wauOrder->id}"))->assertNotFound();
    receiveLines($wauOrder, [
        ['item_id' => $this->amox->id, 'batch_number' => 'X', 'expires_on' => '2027-09-30', 'quantity' => 1, 'unit_cost' => '1.00'],
    ])->assertSessionHasErrors('purchase_order_id');

    $jubaOrder = orderAmoxAndPara();
    receiveLines($jubaOrder, [
        ['item_id' => $this->amox->id, 'purchase_order_line_id' => $wauOrder->lines[0]->id, 'batch_number' => 'X', 'expires_on' => '2027-09-30', 'quantity' => 1, 'unit_cost' => '1.00'],
    ])->assertSessionHasErrors('lines.0.purchase_order_line_id');
});

it('only cancels orders nothing has arrived for', function () {
    storekeeper($this->juba);
    $order = orderAmoxAndPara();
    receiveLines($order, [
        ['item_id' => $this->amox->id, 'purchase_order_line_id' => $order->lines[0]->id, 'batch_number' => 'AMX-1', 'expires_on' => '2027-09-30', 'quantity' => 1, 'unit_cost' => '1.20'],
    ]);

    $this->post($this->workspaceUrl('acme', "purchase-orders/{$order->id}/cancel"))->assertSessionHasErrors('status');
    expect($order->refresh()->status)->toBe(PurchaseOrderStatus::PartlyReceived);
});

it('needs the receive permission in this branch to receive', function () {
    $this->actingAsMember('acme', ['pharmacy.purchasing.view'], branch: $this->juba);

    receiveLines(null, [['item_id' => $this->amox->id, 'batch_number' => 'X', 'expires_on' => '2027-09-30', 'quantity' => 1, 'unit_cost' => '1.00']])
        ->assertForbidden();
});

it('keeps supplier names unique per organization', function () {
    $this->actingAsMember('acme', ['pharmacy.purchasing.view', 'pharmacy.purchasing.manage']);

    $this->post($this->workspaceUrl('acme', 'suppliers'), ['name' => 'Juba Medical Supplies'])->assertSessionHasErrors('name');
    $this->post($this->workspaceUrl('acme', 'suppliers'), ['name' => 'Nile Pharma', 'email' => 'orders@nile.example'])->assertSessionHasNoErrors();

    $this->asTenant('globex', fn () => Supplier::query()->create(['name' => 'Elsewhere Ltd']));
    expect($this->asTenant('acme', fn () => Supplier::query()->pluck('name')->all()))->toBe(['Juba Medical Supplies', 'Nile Pharma']);
});

it('opens every purchasing page', function () {
    storekeeper($this->juba);
    $order = orderAmoxAndPara();
    receiveLines($order, [
        ['item_id' => $this->amox->id, 'purchase_order_line_id' => $order->lines[0]->id, 'batch_number' => 'AMX-1', 'expires_on' => '2027-09-30', 'quantity' => 1, 'unit_cost' => '1.20'],
    ]);
    $receipt = $this->asTenant('acme', fn () => GoodsReceipt::query()->sole());

    foreach ([
        'suppliers' => 'suppliers/index',
        'purchase-orders' => 'purchase-orders/index',
        'purchase-orders/create' => 'purchase-orders/create',
        "purchase-orders/{$order->id}" => 'purchase-orders/show',
        'receipts' => 'receipts/index',
        'receipts/create' => 'receipts/create',
        "receipts/{$receipt->id}" => 'receipts/show',
    ] as $path => $component) {
        $this->get($this->workspaceUrl('acme', $path))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component($component));
    }

    // Receiving against an order starts from what is still expected.
    $this->get($this->workspaceUrl('acme', "receipts/create?order={$order->id}"))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('order.number', 'PO-00001')
            ->where('order.lines.0.remaining', 99));
});

function receiveForReturn(): GoodsReceipt
{
    storekeeper(test()->juba);
    receiveLines(null, [
        ['item_id' => test()->amox->id, 'batch_number' => 'AMX-9', 'expires_on' => '2027-09-30', 'quantity' => 40, 'unit_cost' => '1.20'],
        ['item_id' => test()->para->id, 'batch_number' => 'PAR-9', 'expires_on' => '2026-10-20', 'quantity' => 10, 'unit_cost' => '0.40'],
    ])->assertSessionHasNoErrors();

    return test()->asTenant('acme', fn () => GoodsReceipt::query()->with('lines')->sole());
}

function returnGoods(GoodsReceipt $receipt, array $quantities, string $reason = 'Damaged in transit')
{
    return test()->post(test()->workspaceUrl('acme', "receipts/{$receipt->id}/returns"), [
        'returned_on' => '2026-10-12',
        'reason' => $reason,
        'quantities' => $quantities,
    ]);
}

it('returns goods to the supplier at cost, out of stock, and sends Finance a credit note', function () {
    $receipt = receiveForReturn();
    [$amoxLine, $paraLine] = [$receipt->lines->firstWhere('batch_number', 'AMX-9'), $receipt->lines->firstWhere('batch_number', 'PAR-9')];

    returnGoods($receipt, [$amoxLine->id => 5, $paraLine->id => 0])->assertSessionHasNoErrors();

    $return = $this->asTenant('acme', fn () => SupplierReturn::query()->with('lines')->sole());
    expect($return)->number->toBe('RTN-00001')->total_minor->toBe(5 * 120)->supplier_id->toBe($this->supplier->id)
        ->and($return->lines)->toHaveCount(1)
        ->and($this->asTenant('acme', fn () => Batch::query()->where('batch_number', 'AMX-9')->value('quantity_on_hand')))->toBe(35)
        ->and($this->asTenant('acme', fn () => StockMovement::query()->where('type', 'supplier_return')->value('quantity')))->toBe(-5);

    $credit = Envelope::fromArray(collect($this->platform()->moduleEvents['finance'])->last());
    expect($credit->eventType)->toBe('finance.credit_note.received')
        ->and($credit->payload)->toMatchArray([
            'credit_note_number' => 'RTN-00001', 'bill_number' => $receipt->number, 'supplier_id' => $this->supplier->id,
            'total_minor' => 600, 'currency' => 'SSP', 'reason' => 'Damaged in transit',
        ]);

    // The delivery shows what went back.
    $this->get($this->workspaceUrl('acme', "receipts/{$receipt->id}"))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('returns.0.number', 'RTN-00001')->where('receipt.lines.0.returned', 5));
});

it('never returns more than was received, or more than is left in the batch', function () {
    $receipt = receiveForReturn();
    $amoxLine = $receipt->lines->firstWhere('batch_number', 'AMX-9');

    returnGoods($receipt, [$amoxLine->id => 30])->assertSessionHasNoErrors();
    returnGoods($receipt, [$amoxLine->id => 11])->assertSessionHasErrors("quantities.{$amoxLine->id}");

    // Ten sold or used elsewhere: only what is on the shelf can go back.
    $this->asTenant('acme', fn () => Batch::query()->where('batch_number', 'AMX-9')->update(['quantity_on_hand' => 4]));
    returnGoods($receipt, [$amoxLine->id => 5])->assertSessionHasErrors("quantities.{$amoxLine->id}");

    returnGoods($receipt, [$amoxLine->id => 0])->assertSessionHasErrors('quantities');
    returnGoods($receipt, [(string) str()->uuid() => 1])->assertSessionHasErrors('quantities');
    returnGoods($receipt, ['not-a-line' => 1])->assertSessionHasErrors('quantities');
});

it('takes expired stock back too', function () {
    $receipt = receiveForReturn();
    $this->travelTo(now()->setDate(2026, 10, 25));

    $paraLine = $receipt->lines->firstWhere('batch_number', 'PAR-9');
    $this->post($this->workspaceUrl('acme', "receipts/{$receipt->id}/returns"), [
        'returned_on' => '2026-10-25', 'reason' => 'Expired', 'quantities' => [$paraLine->id => 10],
    ])->assertSessionHasNoErrors();

    expect($this->asTenant('acme', fn () => Batch::query()->where('batch_number', 'PAR-9')->value('quantity_on_hand')))->toBe(0);
});

it('keeps another branch\'s deliveries out of reach, and needs the receive permission', function () {
    $receipt = receiveForReturn();
    $amoxLine = $receipt->lines->firstWhere('batch_number', 'AMX-9');

    storekeeper($this->wau);
    $this->get($this->workspaceUrl('acme', "receipts/{$receipt->id}/return"))->assertNotFound();

    $this->actingAsMember('acme', ['pharmacy.purchasing.view'], branch: $this->juba);
    returnGoods($receipt, [$amoxLine->id => 1])->assertForbidden();
    $this->get($this->workspaceUrl('acme', 'supplier-returns'))->assertOk();
});
