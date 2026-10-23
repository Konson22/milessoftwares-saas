<?php

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Inventory\Actions\Stock;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Pharmacy\Models\Medicine;
use App\Modules\Retail\Models\Product;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Stock-takes: a sheet of the branch's batches holding stock, counted blind,
| line by line; completing it posts each difference as a count correction.
| Each industry counts its own items only.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-24 09:00'));
    $this->juba = $this->platform()->branch('acme', 'Juba');
    $this->asTenant('acme', fn () => Workspace::query()->create(['slug' => 'acme', 'display_name' => 'Acme', 'currency' => 'SSP', 'timezone' => 'Africa/Juba']));

    $this->keeper = User::factory()->create();
    [$this->soap, $this->soapBatch, $this->rice, $this->riceBatch, $this->paraBatch] = $this->asTenant('acme', function () {
        $soap = Product::factory()->create(['name' => 'Soap', 'category' => 'Household']);
        $rice = Product::factory()->create(['name' => 'Rice', 'category' => 'Food']);
        $para = Medicine::factory()->create(['name' => 'Panadol']);
        $stock = app(Stock::class);

        return [
            $soap,
            $stock->add($soap, $this->juba, 'S-1', null, 12, 200, $this->keeper),
            $rice,
            $stock->add($rice, $this->juba, 'R-1', null, 30, 500, $this->keeper),
            $stock->add($para->item, $this->juba, 'P-1', CarbonImmutable::parse('2027-12-31'), 10, 90, $this->keeper),
        ];
    });
});

function countUrl(string $path = '', string $application = 'retail'): string
{
    return test()->workspaceUrl('acme', $path, $application);
}

function counter(array $permissions = ['retail.stock.view', 'retail.stock.adjust']): User
{
    $user = test()->actingAsMember('acme', $permissions, branch: test()->juba, application: 'retail');
    assert($user instanceof User);

    return $user;
}

function openCount(): StockCount
{
    return test()->asTenant('acme', fn () => StockCount::query()->where('status', StockCount::OPEN)->sole());
}

function lineOf(StockCount $count, Batch $batch): string
{
    return test()->asTenant('acme', fn () => $count->lines()->where('batch_id', $batch->id)->value('id'));
}

it('counts blind and posts the differences on completion', function () {
    counter();

    $this->post(countUrl('counts'), [])->assertSessionHasNoErrors();
    $count = openCount();
    expect($count->number)->toStartWith('C');

    // Only the shop's goods are on the sheet, and nothing expected is shown.
    $this->get(countUrl("counts/{$count->id}"))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('retail/counts/show')
        ->has('lines', 2)
        ->where('lines.0.item', 'Rice')
        ->where('lines.0.expected', null)
        ->where('canManage', true));

    $this->put(countUrl("counts/{$count->id}"), ['counts' => [
        lineOf($count, $this->soapBatch) => 10,
        lineOf($count, $this->riceBatch) => 30,
    ]])->assertSessionHasNoErrors();

    $this->post(countUrl("counts/{$count->id}/complete"))->assertSessionHasNoErrors();

    $this->asTenant('acme', function () use ($count) {
        expect($this->soapBatch->fresh()->quantity_on_hand)->toBe(10)
            ->and($this->riceBatch->fresh()->quantity_on_hand)->toBe(30)
            ->and($count->fresh()->status)->toBe(StockCount::COMPLETED);
        $movement = StockMovement::query()->where('reference_type', 'stock_count')->sole();
        expect($movement->quantity)->toBe(-2)
            ->and($movement->reference_id)->toBe($count->id)
            ->and($movement->reason)->toBe('count_correction');
    });

    $this->get(countUrl("counts/{$count->id}"))->assertInertia(fn (Assert $page) => $page
        ->where('lines.1.item', 'Soap')
        ->where('lines.1.expected', 12)
        ->where('lines.1.difference', -2)
        ->where('canManage', false));
});

it('keeps sales made after a line was counted', function () {
    counter();
    $this->post(countUrl('counts'), ['category' => 'Household']);
    $count = openCount();
    expect($this->asTenant('acme', fn () => $count->lines()->count()))->toBe(1);

    // Counted 11 when 12 were on hand (one missing), then 3 are sold.
    $this->put(countUrl("counts/{$count->id}"), ['counts' => [lineOf($count, $this->soapBatch) => 11]]);
    $this->asTenant('acme', fn () => app(Stock::class)->take($this->soap, $this->juba, 3, $this->keeper, 'sale', 'S-TEST'));

    $this->post(countUrl("counts/{$count->id}/complete"))->assertSessionHasNoErrors();

    // 12 − 1 missing − 3 sold.
    expect($this->asTenant('acme', fn () => $this->soapBatch->fresh()->quantity_on_hand))->toBe(8);
});

it('leaves uncounted lines alone and needs something counted', function () {
    counter();
    $this->post(countUrl('counts'));
    $count = openCount();

    $this->post(countUrl("counts/{$count->id}/complete"))->assertSessionHasErrors('count');

    $this->put(countUrl("counts/{$count->id}"), ['counts' => [lineOf($count, $this->riceBatch) => 25]]);
    $this->post(countUrl("counts/{$count->id}/complete"))->assertSessionHasNoErrors();

    $this->asTenant('acme', function () {
        expect($this->riceBatch->fresh()->quantity_on_hand)->toBe(25)
            ->and($this->soapBatch->fresh()->quantity_on_hand)->toBe(12);
    });
});

it('allows one open count per branch, and cancelling changes nothing', function () {
    counter();
    $this->post(countUrl('counts'));
    $count = openCount();

    $this->post(countUrl('counts'))->assertSessionHasErrors('count');

    $this->put(countUrl("counts/{$count->id}"), ['counts' => [lineOf($count, $this->soapBatch) => 0]]);
    $this->post(countUrl("counts/{$count->id}/cancel"))->assertSessionHasNoErrors();
    $this->put(countUrl("counts/{$count->id}"), ['counts' => [lineOf($count, $this->soapBatch) => 1]])->assertSessionHasErrors('count');

    expect($this->asTenant('acme', fn () => $this->soapBatch->fresh()->quantity_on_hand))->toBe(12);

    // A new one can start now.
    $this->post(countUrl('counts'))->assertSessionHasNoErrors();
});

it('rejects lines of another count', function () {
    counter();
    $this->post(countUrl('counts'));
    $count = openCount();

    $this->actingAsMember('acme', ['pharmacy.stock.view', 'pharmacy.stock.adjust'], branch: $this->juba);
    $this->post($this->workspaceUrl('acme', 'counts'));
    $pharmacyCount = $this->asTenant('acme', fn () => StockCount::query()->where('application', 'pharmacy')->sole());

    counter();
    $this->put(countUrl("counts/{$count->id}"), ['counts' => [lineOf($pharmacyCount, $this->paraBatch) => 4]])
        ->assertSessionHasErrors();

    $this->asTenant('acme', fn () => expect($pharmacyCount->lines()->sole()->counted)->toBeNull());
});

it('needs stock.adjust to start or count', function () {
    counter(['retail.stock.view']);

    $this->get(countUrl('counts'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('retail/counts/index')
        ->where('categories', ['Food', 'Household'])
        ->where('canManage', false));
    $this->post(countUrl('counts'))->assertForbidden();
});

it('keeps each industry to its own counts', function () {
    counter();
    $this->post(countUrl('counts'));
    $shopCount = openCount();

    // The pharmacy at the same branch counts its medicine, independently.
    $this->actingAsMember('acme', ['pharmacy.stock.view', 'pharmacy.stock.adjust'], branch: $this->juba);
    $this->get($this->workspaceUrl('acme', "counts/{$shopCount->id}"))->assertNotFound();
    $this->post($this->workspaceUrl('acme', "counts/{$shopCount->id}/complete"))->assertNotFound();

    $this->post($this->workspaceUrl('acme', 'counts'))->assertSessionHasNoErrors();
    $pharmacyCount = $this->asTenant('acme', fn () => StockCount::query()->where('application', 'pharmacy')->sole());

    $this->get($this->workspaceUrl('acme', "counts/{$pharmacyCount->id}"))->assertInertia(fn (Assert $page) => $page
        ->component('counts/show')
        ->has('lines', 1)
        ->where('lines.0.batchNumber', 'P-1'));

    counter();
    $this->get(countUrl("counts/{$pharmacyCount->id}"))->assertNotFound();
});
