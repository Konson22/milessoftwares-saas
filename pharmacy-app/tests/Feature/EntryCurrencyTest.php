<?php

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Inventory\Actions\Stock;
use App\Modules\MasterData\Models\Supplier;
use App\Modules\Pharmacy\Models\Medicine;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\TillSession;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Miles\PlatformClient\Money\EnteredAmountRecord;
use Miles\PlatformClient\Money\EntryCurrencies;
use Miles\PlatformClient\Money\EntryCurrency;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 08:00'));
    $this->juba = $this->platform()->branch('acme', 'Juba');
    $this->asTenant('acme', fn () => Workspace::query()->create(['display_name' => 'Acme', 'currency' => 'SSP', 'timezone' => 'Africa/Juba']));
    $this->para = $this->asTenant('acme', fn () => Medicine::factory()->create(['sell_price_minor' => 1560000]));
    $this->asTenant('acme', fn () => app(Stock::class)->add($this->para->item, $this->juba, 'P-1', CarbonImmutable::parse('2027-12-31'), 100, 100, User::factory()->create()));
});

it('lets a manager add a currency with its rate, and shares it with every page', function () {
    $this->actingAsMember('acme', ['pharmacy.sales.settings'], branch: $this->juba);

    $this->post($this->workspaceUrl('acme', 'currencies'), ['code' => 'usd', 'rate' => '7800'])->assertSessionHasNoErrors();
    $this->post($this->workspaceUrl('acme', 'currencies'), ['code' => 'SSP', 'rate' => '1'])->assertSessionHasErrors('code');

    $this->get($this->workspaceUrl('acme', 'currencies'))->assertInertia(fn (Assert $page) => $page
        ->component('currencies')
        ->where('canManage', true)
        ->where('money.base', 'SSP')
        ->where('money.currencies.1.code', 'USD')
        ->where('money.currencies.1.rate', '7800.00'));

    $this->delete($this->workspaceUrl('acme', 'currencies/USD'))->assertSessionHasNoErrors();
    expect($this->asTenant('acme', fn () => EntryCurrency::query()->count()))->toBe(0);
});

it('keeps currencies to members who manage counter settings', function () {
    $this->actingAsMember('acme', ['pharmacy.sales.create'], branch: $this->juba);

    $this->get($this->workspaceUrl('acme', 'currencies'))->assertInertia(fn (Assert $page) => $page->where('canManage', false));
    $this->post($this->workspaceUrl('acme', 'currencies'), ['code' => 'USD', 'rate' => '7800'])->assertForbidden();
});

it('takes cash in another currency and gives change in the workspace currency', function () {
    $this->asTenant('acme', fn () => app(EntryCurrencies::class)->set('USD', '7800'));
    $this->actingAsMember('acme', ['pharmacy.sales.create'], branch: $this->juba);

    $this->post($this->workspaceUrl('acme', 'tills'), ['opening_float' => '10', 'opening_float_currency' => 'USD'])->assertSessionHasNoErrors();
    $this->post($this->workspaceUrl('acme', 'sales'), [
        'items' => [['item_id' => $this->para->id, 'quantity' => 1]],
        'payment_method' => 'cash',
        'amount_tendered' => '2.50',
        'amount_tendered_currency' => 'USD',
    ])->assertSessionHasNoErrors();

    $this->asTenant('acme', function () {
        $sale = Sale::query()->sole();
        expect($sale->total_minor)->toBe(1560000)
            ->and($sale->amount_tendered_minor)->toBe(1950000)
            ->and($sale->change_minor)->toBe(390000)
            ->and(TillSession::query()->sole()->opening_float_minor)->toBe(7800000)
            ->and(app(EntryCurrencies::class)->enteredOn($sale))->toBe([
                'amount_tendered' => ['currency' => 'USD', 'amount' => '2.50', 'rate' => '7800.00', 'base' => '19500.00'],
            ]);
    });
});

it('refuses an amount in a currency without a rate, on the field', function () {
    $this->actingAsMember('acme', ['pharmacy.sales.create'], branch: $this->juba);

    $this->post($this->workspaceUrl('acme', 'tills'), ['opening_float' => '10', 'opening_float_currency' => 'EUR'])->assertSessionHasErrors('opening_float');
    expect($this->asTenant('acme', fn () => TillSession::query()->count()))->toBe(0);
});

it('orders from a supplier in another currency and keeps the costs in the workspace currency', function () {
    $this->asTenant('acme', fn () => app(EntryCurrencies::class)->set('USD', '7800'));
    $supplier = $this->asTenant('acme', fn () => Supplier::query()->create(['name' => 'Kampala Pharma', 'active' => true]));
    $this->actingAsMember('acme', ['pharmacy.purchasing.view', 'pharmacy.purchasing.manage'], branch: $this->juba);

    $this->post($this->workspaceUrl('acme', 'purchase-orders'), [
        'supplier_id' => $supplier->id,
        'currency' => 'USD',
        'lines' => [['item_id' => $this->para->item->id, 'quantity' => 10, 'unit_cost' => '1.25']],
    ])->assertSessionHasNoErrors();

    $this->asTenant('acme', function () {
        $order = PurchaseOrder::query()->with('lines')->sole();
        expect($order->lines->sole()->unit_cost_minor)->toBe(975000)
            ->and(EnteredAmountRecord::query()->sole())
            ->field->toBe('lines.0.unit_cost')
            ->currency->toBe('USD')
            ->amount_minor->toBe(125);
    });
});

it('saves a price typed in another currency in the workspace currency', function () {
    $this->asTenant('acme', fn () => app(EntryCurrencies::class)->set('USD', '7800'));
    $this->actingAsMember('acme', ['pharmacy.medicines.view', 'pharmacy.medicines.manage']);

    $this->put($this->workspaceUrl('acme', 'medicines/'.$this->para->id), [
        'name' => 'Paracetamol',
        'generic_name' => 'Paracetamol',
        'form' => 'tablet',
        'unit' => 'tablet',
        'reorder_level' => 0,
        'sell_price' => '3',
        'sell_price_currency' => 'USD',
        'packs' => [['name' => 'Box', 'units' => 10, 'sell_price' => '25']],
    ])->assertSessionHasNoErrors()->assertRedirect();

    $this->asTenant('acme', function () {
        $medicine = $this->para->fresh(['item.packs']);
        expect($medicine->sell_price_minor)->toBe(2340000)
            ->and($medicine->item->packs->sole()->sell_price_minor)->toBe(19500000);
    });
});
