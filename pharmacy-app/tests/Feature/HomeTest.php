<?php

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Inventory\Actions\Stock;
use App\Modules\Pharmacy\Models\Medicine;
use App\Modules\Sales\Actions\Sell;
use App\Modules\Sales\Enums\PaymentMethod;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00'));
    $this->juba = $this->platform()->branch('acme', 'Juba');
    $this->asTenant('acme', fn () => Workspace::query()->create(['slug' => 'acme', 'display_name' => 'Acme', 'currency' => 'SSP', 'timezone' => 'Africa/Juba']));

    $this->asTenant('acme', function () {
        $keeper = User::factory()->create();
        $para = Medicine::factory()->create(['reorder_level' => 50, 'sell_price_minor' => 250]);
        Medicine::factory()->create(['reorder_level' => 0]); // none in stock, nothing to reorder
        app(Stock::class)->add($para, $this->juba, 'P-1', CarbonImmutable::parse('2026-11-30'), 40, 100, $keeper);
        app(Sell::class)->handle($keeper, $this->juba, [['medicine_id' => $para->id, 'quantity' => 4]], PaymentMethod::MobileMoney);
    });
});

it('shows the branch at a glance', function () {
    $this->actingAsMember('acme', ['pharmacy.sales.view', 'pharmacy.stock.view', 'pharmacy.prescriptions.view'], branch: $this->juba);

    $this->get($this->workspaceUrl('acme'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('home')
            ->where('sales.count', 1)
            ->where('sales.totalMinor', 1000)
            ->where('stock.low', 2)
            ->where('stock.expiring', 1)
            ->where('awaitingVerification', 0));
});

it('shows a cashier only what a cashier may see', function () {
    $this->actingAsMember('acme', ['pharmacy.sales.create'], branch: $this->juba);

    $this->get($this->workspaceUrl('acme'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('sales', null)
            ->where('stock', null)
            ->where('awaitingVerification', null));
});
