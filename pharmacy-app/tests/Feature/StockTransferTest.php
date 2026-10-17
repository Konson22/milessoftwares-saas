<?php

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Inventory\Actions\Stock;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\BatchLocation;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Pharmacy\Models\Medicine;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18 08:00'));
    $this->juba = $this->platform()->branch('acme', 'Juba');
    $this->wau = $this->platform()->branch('acme', 'Wau');
    $this->yei = $this->platform()->branch('acme', 'Yei');
    $this->asTenant('acme', fn () => Workspace::query()->create(['slug' => 'acme', 'display_name' => 'Acme', 'currency' => 'SSP', 'timezone' => 'Africa/Juba']));

    $this->para = $this->asTenant('acme', fn () => Medicine::factory()->create(['sell_price_minor' => 250]));
    $this->batch = $this->asTenant('acme', fn () => app(Stock::class)->add($this->para->item, $this->juba, 'P-1', CarbonImmutable::parse('2027-06-30'), 30, 100, User::factory()->create()));
});

function sendToWau(array $lines, ?string $to = null): TestResponse
{
    return test()->post(test()->workspaceUrl('acme', 'transfers'), ['to_branch_id' => $to ?? test()->wau, 'lines' => $lines, 'note' => 'With the Tuesday van']);
}

function batchIn(string $branchId): ?Batch
{
    return test()->asTenant('acme', fn () => Batch::query()->where('branch_id', $branchId)->first());
}

it('sends stock out of one branch and receives it into the same batch in the other', function () {
    $this->actingAsMember('acme', ['pharmacy.stock.view', 'pharmacy.stock.adjust'], branch: $this->juba);
    $this->get($this->workspaceUrl('acme', 'transfers/create'))->assertInertia(fn (Assert $page) => $page
        ->where('branches', [['id' => $this->wau, 'name' => 'Wau'], ['id' => $this->yei, 'name' => 'Yei']])
        ->where('batches.0.onHand', 30));

    sendToWau([['batch_id' => $this->batch->id, 'quantity' => 12]])->assertSessionHasNoErrors();
    $transfer = $this->asTenant('acme', fn () => StockTransfer::query()->sole());

    // On the road: out of Juba, not yet in Wau.
    expect($transfer->status)->toBe(StockTransfer::IN_TRANSIT)
        ->and(batchIn($this->juba)->quantity_on_hand)->toBe(18)
        ->and(batchIn($this->wau))->toBeNull();
    // Juba cannot receive its own transfer.
    $this->post($this->workspaceUrl('acme', "transfers/{$transfer->id}/receive"))->assertNotFound();

    $this->actingAsMember('acme', ['pharmacy.stock.view', 'pharmacy.stock.adjust'], branch: $this->wau);
    $shelf = $this->asTenant('acme', fn () => StockLocation::query()->create(['branch_id' => $this->wau, 'name' => 'Front shelf']));
    $this->get($this->workspaceUrl('acme', 'transfers'))->assertInertia(fn (Assert $page) => $page
        ->where('transfers.0.from', 'Juba')
        ->where('transfers.0.units', 12));
    $this->get($this->workspaceUrl('acme', "transfers/{$transfer->id}"))->assertInertia(fn (Assert $page) => $page
        ->where('canReceive', true)
        ->where('canCancel', false)
        ->where('transfer.lines.0.costMinor', 1200));
    $this->post($this->workspaceUrl('acme', "transfers/{$transfer->id}/cancel"))->assertNotFound();
    $this->post($this->workspaceUrl('acme', "transfers/{$transfer->id}/receive"), ['location_id' => $shelf->id])->assertSessionHasNoErrors();
    $this->post($this->workspaceUrl('acme', "transfers/{$transfer->id}/receive"))->assertSessionHasErrors('transfer');

    $arrived = batchIn($this->wau);
    expect($arrived)
        ->batch_number->toBe('P-1')
        ->quantity_on_hand->toBe(12)
        ->unit_cost_minor->toBe(100)
        ->and($arrived->expires_on->toDateString())->toBe('2027-06-30')
        ->and($transfer->refresh()->status)->toBe(StockTransfer::RECEIVED)
        ->and($this->asTenant('acme', fn () => (int) BatchLocation::query()->where('stock_location_id', $shelf->id)->sum('quantity')))->toBe(12);
});

it('puts the stock back when the sender cancels', function () {
    $this->actingAsMember('acme', ['pharmacy.stock.view', 'pharmacy.stock.adjust'], branch: $this->juba);
    sendToWau([['batch_id' => $this->batch->id, 'quantity' => 10]])->assertSessionHasNoErrors();
    $transfer = $this->asTenant('acme', fn () => StockTransfer::query()->sole());

    $this->post($this->workspaceUrl('acme', "transfers/{$transfer->id}/cancel"))->assertSessionHasNoErrors();

    expect(batchIn($this->juba)->quantity_on_hand)->toBe(30)
        ->and($transfer->refresh()->status)->toBe(StockTransfer::CANCELLED);

    $this->actingAsMember('acme', ['pharmacy.stock.view', 'pharmacy.stock.adjust'], branch: $this->wau);
    $this->post($this->workspaceUrl('acme', "transfers/{$transfer->id}/receive"))->assertSessionHasErrors('transfer');
    expect(batchIn($this->wau))->toBeNull();
});

it('sends only stock the branch has, in date and off hold, to another branch', function () {
    $this->actingAsMember('acme', ['pharmacy.stock.view', 'pharmacy.stock.adjust', 'pharmacy.stock.hold'], branch: $this->juba);

    sendToWau([['batch_id' => $this->batch->id, 'quantity' => 31]])->assertSessionHasErrors('lines.0.quantity');
    sendToWau([['batch_id' => $this->batch->id, 'quantity' => 1]], $this->juba)->assertSessionHasErrors('to_branch_id');
    sendToWau([['batch_id' => $this->batch->id, 'quantity' => 1], ['batch_id' => $this->batch->id, 'quantity' => 1]])->assertSessionHasErrors('lines.1.batch_id');

    $wauBatch = $this->asTenant('acme', fn () => app(Stock::class)->add($this->para->item, $this->wau, 'W-1', CarbonImmutable::parse('2027-06-30'), 5, 100, User::factory()->create()));
    sendToWau([['batch_id' => $wauBatch->id, 'quantity' => 1]])->assertSessionHasErrors('lines.0.batch_id');

    $this->post($this->workspaceUrl('acme', "batches/{$this->batch->id}/hold"), ['status' => 'quarantined', 'reason' => 'Odd colour'])->assertSessionHasNoErrors();
    sendToWau([['batch_id' => $this->batch->id, 'quantity' => 1]])->assertSessionHasErrors('lines.0.batch_id');

    expect($this->asTenant('acme', fn () => StockTransfer::query()->count()))->toBe(0)
        ->and(batchIn($this->juba)->quantity_on_hand)->toBe(30);
});

it('keeps transfers to their two branches and to members who may move stock', function () {
    $this->actingAsMember('acme', ['pharmacy.stock.view', 'pharmacy.stock.adjust'], branch: $this->juba);
    sendToWau([['batch_id' => $this->batch->id, 'quantity' => 2]])->assertSessionHasNoErrors();
    $transfer = $this->asTenant('acme', fn () => StockTransfer::query()->sole());

    $this->actingAsMember('acme', ['pharmacy.stock.view', 'pharmacy.stock.adjust'], branch: $this->yei);
    $this->get($this->workspaceUrl('acme', "transfers/{$transfer->id}"))->assertNotFound();

    $this->actingAsMember('acme', ['pharmacy.stock.view'], branch: $this->wau);
    $this->get($this->workspaceUrl('acme', "transfers/{$transfer->id}"))->assertOk()->assertInertia(fn (Assert $page) => $page->where('canReceive', false));
    $this->post($this->workspaceUrl('acme', "transfers/{$transfer->id}/receive"))->assertForbidden();
});
