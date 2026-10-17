<?php

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Inventory\Actions\Stock;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Pharmacy\Models\Medicine;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 09:00'));
    $this->juba = $this->platform()->branch('acme', 'Juba');
    $this->wau = $this->platform()->branch('acme', 'Wau');
    $this->asTenant('acme', fn () => Workspace::query()->create(['slug' => 'acme', 'display_name' => 'Acme', 'currency' => 'SSP', 'timezone' => 'Africa/Juba']));
    $this->medicine = $this->asTenant('acme', fn () => Medicine::factory()->create(['reorder_level' => 20]));
});

function stockIn(string $branch, string $batch, string $expires, int $quantity): Batch
{
    return test()->asTenant('acme', fn () => app(Stock::class)->add(
        test()->medicine->item, $branch, $batch, CarbonImmutable::parse($expires), $quantity, 100, User::factory()->create(),
    ));
}

function workIn(string $branch): void
{
    test()->put(test()->workspaceUrl('acme', 'branch'), ['branch_id' => $branch]);
}

it('shows the stock of the branch being worked in only', function () {
    stockIn($this->juba, 'J1', '2027-06-30', 15);
    stockIn($this->wau, 'W1', '2027-06-30', 500);
    $this->actingAsMember('acme', ['pharmacy.stock.view']);
    workIn($this->juba);

    $this->get($this->workspaceUrl('acme', 'stock'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('stock/index')
            ->where('rows.0.sellable', 15)
            ->where('rows.0.low', true));
});

it('flags expiring and expired stock', function () {
    stockIn($this->juba, 'SOON', '2026-12-01', 50);
    stockIn($this->juba, 'GONE', '2026-10-01', 7);
    $this->actingAsMember('acme', ['pharmacy.stock.view']);
    workIn($this->juba);

    $this->get($this->workspaceUrl('acme', 'stock?filter=expired'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows', 1)
            ->where('rows.0.expired', 7)
            ->where('rows.0.sellable', 50)
            ->where('rows.0.expiringSoon', true)
            ->where('rows.0.nextExpiry', '2026-12-01'));
});

it('lets a storekeeper add opening stock and adjust it in their branch', function () {
    $this->actingAsMember('acme', ['pharmacy.stock.view', 'pharmacy.stock.adjust'], branch: $this->juba);

    $this->post($this->workspaceUrl('acme', "stock/{$this->medicine->id}/opening"), [
        'batch_number' => 'OPEN-1', 'expires_on' => '2027-03-31', 'quantity' => 40, 'unit_cost' => '1.20',
    ])->assertSessionHasNoErrors();

    $batch = $this->asTenant('acme', fn () => Batch::query()->sole());
    expect($batch)->quantity_on_hand->toBe(40)->unit_cost_minor->toBe(120)->branch_id->toBe($this->juba);

    $this->post($this->workspaceUrl('acme', "batches/{$batch->id}/adjustments"), ['change' => -5, 'reason' => 'count_correction'])
        ->assertSessionHasNoErrors();
    $this->post($this->workspaceUrl('acme', "batches/{$batch->id}/adjustments"), ['change' => -50, 'reason' => 'count_correction'])
        ->assertSessionHasErrors('change');
    $this->post($this->workspaceUrl('acme', "batches/{$batch->id}/adjustments"), ['change' => 2, 'reason' => 'other'])
        ->assertSessionHasErrors('note');

    expect($batch->refresh()->quantity_on_hand)->toBe(35);
});

it('refuses adjustments without the permission in this branch', function () {
    $batch = stockIn($this->juba, 'J1', '2027-06-30', 10);

    // Allowed to adjust in Wau only, working in Juba with a view-only role.
    $user = $this->actingAsMember('acme');
    $this->platform()->grant('acme', $user->getAuthIdentifier(), [
        ['permissions' => ['pharmacy.stock.view'], 'scope' => ['scope_type' => 'branch', 'branch_id' => $this->juba]],
        ['permissions' => ['pharmacy.stock.view', 'pharmacy.stock.adjust'], 'scope' => ['scope_type' => 'branch', 'branch_id' => $this->wau]],
    ]);
    workIn($this->juba);

    $this->post($this->workspaceUrl('acme', "batches/{$batch->id}/adjustments"), ['change' => -1, 'reason' => 'theft_or_loss'])
        ->assertForbidden();
    expect($batch->refresh()->quantity_on_hand)->toBe(10);
});

it('treats another branch\'s batch as unknown', function () {
    $wauBatch = stockIn($this->wau, 'W1', '2027-06-30', 10);
    $this->actingAsMember('acme', ['pharmacy.stock.view', 'pharmacy.stock.adjust']);
    workIn($this->juba);

    $this->post($this->workspaceUrl('acme', "batches/{$wauBatch->id}/adjustments"), ['change' => -1, 'reason' => 'theft_or_loss'])
        ->assertNotFound();
    expect($wauBatch->refresh()->quantity_on_hand)->toBe(10);
});

it('shows a medicine\'s batches and history in the branch', function () {
    stockIn($this->juba, 'J1', '2027-06-30', 10);
    $this->actingAsMember('acme', ['pharmacy.stock.view'], branch: $this->juba);

    $this->get($this->workspaceUrl('acme', "stock/{$this->medicine->id}"))
        ->assertInertia(fn (Assert $page) => $page
            ->component('stock/show')
            ->has('batches', 1)
            ->has('movements', 1)
            ->where('movements.0.type', 'receipt')
            ->where('canAdjust', false));
});
