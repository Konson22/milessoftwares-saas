<?php

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Inventory\Actions\Stock;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\StockDisposal;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\MasterData\Models\Supplier;
use App\Modules\Pharmacy\Models\Medicine;
use App\Modules\Procurement\Models\GoodsReceipt;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\SupplierReturn;
use App\Modules\Retail\Models\Product;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Miles\SharedModuleKit\Envelope;

/*
| Retail R2 (docs/erp/retail-roadmap.md): the shop buys, receives, returns,
| writes off and moves its own products on the shared Procurement and
| Inventory engine. Its documents are its own: the pharmacy never sees them,
| and the shop never sees the pharmacy's.
*/

beforeEach(function () {
    config(['platform-client.modules.finance' => 'https://finance.internal/events']);
    $this->travelTo(CarbonImmutable::parse('2026-10-20 09:00'));
    $this->juba = $this->platform()->branch('acme', 'Juba');
    $this->wau = $this->platform()->branch('acme', 'Wau');
    $this->asTenant('acme', fn () => Workspace::query()->create(['slug' => 'acme', 'display_name' => 'Acme', 'currency' => 'SSP', 'timezone' => 'Africa/Juba']));
    $this->supplier = $this->asTenant('acme', fn () => Supplier::query()->create(['name' => 'Juba Wholesale']));
    $this->soap = $this->asTenant('acme', fn () => Product::factory()->create(['name' => 'Soap']));
    $this->para = $this->asTenant('acme', fn () => Medicine::factory()->create(['name' => 'Panadol']));
});

function shopUrl(string $path = ''): string
{
    return test()->workspaceUrl('acme', $path, 'retail');
}

function shopBuyer(array $extra = []): void
{
    test()->actingAsMember('acme', ['retail.purchasing.view', 'retail.purchasing.manage', 'retail.purchasing.receive', 'retail.stock.view', ...$extra], branch: test()->juba, application: 'retail');
}

function shopReceive(array $lines, ?PurchaseOrder $order = null, string $reference = 'DN-100')
{
    return test()->post(shopUrl('receipts'), [
        'supplier_id' => test()->supplier->id,
        'purchase_order_id' => $order?->id,
        'supplier_reference' => $reference,
        'received_on' => '2026-10-20',
        'lines' => $lines,
    ]);
}

function shopStock(string $batch = 'LOT-1', int $quantity = 20): Batch
{
    $keeper = User::factory()->create();

    return test()->asTenant('acme', fn () => app(Stock::class)->add(test()->soap, test()->juba, $batch, null, $quantity, 150, $keeper));
}

describe('purchase orders and deliveries', function () {
    it('orders and receives products as the shop, undated, and bills Finance as retail', function () {
        shopBuyer();

        $this->post(shopUrl('purchase-orders'), [
            'supplier_id' => $this->supplier->id,
            'lines' => [['item_id' => $this->soap->id, 'quantity' => 24, 'unit_cost' => '1.50']],
        ])->assertSessionHasNoErrors();
        $order = $this->asTenant('acme', fn () => PurchaseOrder::query()->with('lines')->sole());
        expect($order->application)->toBe('retail');
        $this->post(shopUrl("purchase-orders/{$order->id}/ordered"))->assertSessionHasNoErrors();

        shopReceive([[
            'item_id' => $this->soap->id, 'purchase_order_line_id' => $order->lines[0]->id,
            'batch_number' => 'LOT-1', 'quantity' => 24, 'unit_cost' => '1.50',
        ]], $order)->assertSessionHasNoErrors();

        $receipt = $this->asTenant('acme', fn () => GoodsReceipt::query()->sole());
        $batch = $this->asTenant('acme', fn () => Batch::query()->sole());
        expect($receipt->application)->toBe('retail')
            ->and($batch->expires_on)->toBeNull()
            ->and($batch->quantity_on_hand)->toBe(24);

        $bill = Envelope::fromArray($this->platform()->moduleEvents['finance'][0]);
        expect($bill->eventType)->toBe('finance.bill.received')
            ->and($bill->sourceApp)->toBe('retail')
            ->and($bill->payload['total_minor'])->toBe(3600);

        $this->get(shopUrl("receipts/{$receipt->id}"))->assertInertia(fn (Assert $page) => $page->component('retail/receipts/show'));
        $this->get(shopUrl('receipts/create'))->assertInertia(fn (Assert $page) => $page
            ->component('retail/receipts/create')
            ->has('items', 1)
            ->where('items.0.tracksExpiry', false));
    });

    it('never buys or receives a medicine for the shop', function () {
        shopBuyer();

        $this->post(shopUrl('purchase-orders'), [
            'supplier_id' => $this->supplier->id,
            'lines' => [['item_id' => $this->para->id, 'quantity' => 1, 'unit_cost' => '1.00']],
        ])->assertSessionHasErrors(['lines.0.item_id' => 'This item is not bought here.']);

        shopReceive([['item_id' => $this->para->id, 'batch_number' => 'P-1', 'expires_on' => '2027-12-31', 'quantity' => 1, 'unit_cost' => '1.00']])
            ->assertSessionHasErrors(['lines.0.item_id' => 'This item is not bought here.']);

        expect($this->asTenant('acme', fn () => PurchaseOrder::query()->count() + GoodsReceipt::query()->count() + Batch::query()->count()))->toBe(0);
    });

    it('receives the same delivery note once, however it is typed', function () {
        shopBuyer();
        $line = ['item_id' => $this->soap->id, 'batch_number' => 'LOT-1', 'quantity' => 5, 'unit_cost' => '1.00'];

        shopReceive([$line], reference: 'DN-100')->assertSessionHasNoErrors();
        shopReceive([$line], reference: ' dn-100 ')->assertSessionHasErrors(['supplier_reference' => 'dn-100 from Juba Wholesale was already received as GRN-00001.']);
        shopReceive([$line], reference: 'DN-101')->assertSessionHasNoErrors();

        expect($this->asTenant('acme', fn () => (int) Batch::query()->sum('quantity_on_hand')))->toBe(10)
            ->and($this->platform()->moduleEvents['finance'])->toHaveCount(2);
    });

    it('returns goods from a shop delivery as retail', function () {
        shopBuyer();
        shopReceive([['item_id' => $this->soap->id, 'batch_number' => 'LOT-1', 'quantity' => 10, 'unit_cost' => '1.00']])->assertSessionHasNoErrors();
        $receipt = $this->asTenant('acme', fn () => GoodsReceipt::query()->with('lines')->sole());

        $this->get(shopUrl("receipts/{$receipt->id}/return"))->assertInertia(fn (Assert $page) => $page->component('retail/supplier-returns/create'));
        $this->post(shopUrl("receipts/{$receipt->id}/returns"), [
            'returned_on' => '2026-10-20',
            'reason' => 'Crushed boxes',
            'quantities' => [$receipt->lines[0]->id => 3],
        ])->assertSessionHasNoErrors();

        $return = $this->asTenant('acme', fn () => SupplierReturn::query()->sole());
        expect($return->application)->toBe('retail')
            ->and($this->asTenant('acme', fn () => (int) Batch::query()->sum('quantity_on_hand')))->toBe(7);
        $this->get(shopUrl("supplier-returns/{$return->id}"))->assertOk();
    });
});

describe('the shop and the pharmacy keep their documents apart', function () {
    it('lists and opens only its own orders, deliveries and returns', function () {
        // The pharmacy buys and receives first.
        $this->actingAsMember('acme', ['pharmacy.purchasing.view', 'pharmacy.purchasing.manage', 'pharmacy.purchasing.receive'], branch: $this->juba);
        $this->post($this->workspaceUrl('acme', 'purchase-orders'), [
            'supplier_id' => $this->supplier->id,
            'lines' => [['item_id' => $this->para->id, 'quantity' => 10, 'unit_cost' => '1.00']],
        ])->assertSessionHasNoErrors();
        $this->post($this->workspaceUrl('acme', 'receipts'), [
            'supplier_id' => $this->supplier->id,
            'supplier_reference' => 'INV-1',
            'received_on' => '2026-10-20',
            'lines' => [['item_id' => $this->para->id, 'batch_number' => 'P-1', 'expires_on' => '2027-12-31', 'quantity' => 10, 'unit_cost' => '1.00']],
        ])->assertSessionHasNoErrors();
        $order = $this->asTenant('acme', fn () => PurchaseOrder::query()->sole());
        $this->post($this->workspaceUrl('acme', "purchase-orders/{$order->id}/ordered"))->assertSessionHasNoErrors();
        $receipt = $this->asTenant('acme', fn () => GoodsReceipt::query()->sole());

        shopBuyer();
        $this->get(shopUrl('purchase-orders'))->assertInertia(fn (Assert $page) => $page->has('orders', 0));
        $this->get(shopUrl('receipts'))->assertInertia(fn (Assert $page) => $page->has('receipts', 0));
        $this->get(shopUrl("purchase-orders/{$order->id}"))->assertNotFound();
        $this->get(shopUrl("receipts/{$receipt->id}"))->assertNotFound();
        $this->get(shopUrl("receipts/create?order={$order->id}"))->assertNotFound();
        $this->get(shopUrl("receipts/{$receipt->id}/return"))->assertNotFound();
        $this->post(shopUrl("purchase-orders/{$order->id}/cancel"))->assertNotFound();

        // Nor can the shop receive into the pharmacy's order (sent, so receivable).
        shopReceive([['item_id' => $this->soap->id, 'batch_number' => 'LOT-1', 'quantity' => 1, 'unit_cost' => '1.00']], $order)
            ->assertSessionHasErrors(['purchase_order_id' => 'That order is for another branch or supplier.']);
    });

    it('needs retail permissions, not the pharmacy\'s', function () {
        $this->actingAsMember('acme', ['pharmacy.purchasing.view', 'pharmacy.purchasing.manage', 'pharmacy.stock.view', 'pharmacy.stock.adjust'], branch: $this->juba);

        $this->get(shopUrl('purchase-orders'))->assertForbidden();
        $this->get(shopUrl('suppliers'))->assertForbidden();
        $this->get(shopUrl('transfers'))->assertForbidden();
    });

    it('shares suppliers with the pharmacy', function () {
        shopBuyer();

        $this->get(shopUrl('suppliers'))->assertInertia(fn (Assert $page) => $page
            ->component('retail/suppliers/index')
            ->where('suppliers.0.name', 'Juba Wholesale')
            ->where('canManage', true));
    });
});

describe('write-offs', function () {
    it('writes a product off once someone else approves, as retail', function () {
        $batch = shopStock();
        $this->actingAsMember('acme', ['retail.stock.view', 'retail.stock.adjust'], branch: $this->juba, application: 'retail');
        $this->post(shopUrl("batches/{$batch->id}/disposals"), ['quantity' => 4, 'reason' => 'damaged', 'note' => 'Dropped'])->assertSessionHasNoErrors();
        $disposal = $this->asTenant('acme', fn () => StockDisposal::query()->sole());
        expect($disposal->application)->toBe('retail');

        // Asking is not deciding.
        $this->post(shopUrl("disposals/{$disposal->id}/approve"))->assertForbidden();

        $this->actingAsMember('acme', ['retail.stock.view', 'retail.stock.dispose'], branch: $this->juba, application: 'retail');
        $this->get(shopUrl('disposals'))->assertInertia(fn (Assert $page) => $page
            ->component('retail/disposals/index')
            ->has('disposals', 1)
            ->where('canDecide', true));
        $this->post(shopUrl("disposals/{$disposal->id}/approve"))->assertSessionHasNoErrors();

        expect($batch->fresh()->quantity_on_hand)->toBe(16);

        // The pharmacy's list does not show the shop's write-offs.
        $this->actingAsMember('acme', ['pharmacy.stock.adjust', 'pharmacy.stock.dispose'], branch: $this->juba);
        $this->get($this->workspaceUrl('acme', 'disposals?status=approved'))->assertInertia(fn (Assert $page) => $page->has('disposals', 0));
        $this->post($this->workspaceUrl('acme', "disposals/{$disposal->id}/reject"), ['note' => 'No'])->assertNotFound();
    });

    it('never writes off a medicine from the shop', function () {
        $keeper = User::factory()->create();
        $batch = $this->asTenant('acme', fn () => app(Stock::class)->add($this->para->item, $this->juba, 'P-1', CarbonImmutable::parse('2027-12-31'), 5, 100, $keeper));
        $this->actingAsMember('acme', ['retail.stock.adjust'], branch: $this->juba, application: 'retail');

        $this->post(shopUrl("batches/{$batch->id}/disposals"), ['quantity' => 1, 'reason' => 'damaged'])->assertNotFound();

        expect($this->asTenant('acme', fn () => StockDisposal::query()->count()))->toBe(0);
    });
});

describe('transfers', function () {
    it('sends products to another branch as retail, and offers no medicines', function () {
        $batch = shopStock();
        $keeper = User::factory()->create();
        $this->asTenant('acme', fn () => app(Stock::class)->add($this->para->item, $this->juba, 'P-1', CarbonImmutable::parse('2027-12-31'), 5, 100, $keeper));
        $this->actingAsMember('acme', ['retail.stock.view', 'retail.stock.adjust'], branch: $this->juba, application: 'retail');

        $this->get(shopUrl('transfers/create'))->assertInertia(fn (Assert $page) => $page
            ->component('retail/transfers/create')
            ->has('batches', 1)
            ->where('batches.0.label', 'Soap · LOT-1'));

        $this->post(shopUrl('transfers'), ['to_branch_id' => $this->wau, 'lines' => [['batch_id' => $batch->id, 'quantity' => 5]]])->assertSessionHasNoErrors();
        $transfer = $this->asTenant('acme', fn () => StockTransfer::query()->sole());
        expect($transfer->application)->toBe('retail');

        // Received at Wau through the shop.
        $this->actingAsMember('acme', ['retail.stock.view', 'retail.stock.adjust'], branch: $this->wau, application: 'retail');
        $this->post(shopUrl("transfers/{$transfer->id}/receive"))->assertSessionHasNoErrors();
        expect($this->asTenant('acme', fn () => (int) Batch::query()->where('branch_id', $this->wau)->sum('quantity_on_hand')))->toBe(5);

        // Not one of the pharmacy's transfers.
        $this->actingAsMember('acme', ['pharmacy.stock.view'], branch: $this->wau);
        $this->get($this->workspaceUrl('acme', 'transfers'))->assertInertia(fn (Assert $page) => $page->has('transfers', 0));
        $this->get($this->workspaceUrl('acme', "transfers/{$transfer->id}"))->assertNotFound();
    });
});
