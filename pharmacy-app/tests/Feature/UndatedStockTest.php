<?php

use App\Models\User;
use App\Modules\Inventory\Actions\Stock;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Item;
use App\Modules\MasterData\Models\Supplier;
use App\Modules\Pharmacy\Models\Medicine;
use App\Modules\Procurement\Actions\ReceiveGoods;
use App\Modules\Sales\Actions\Sell;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Models\Sale;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/*
| Goods that never expire (ADR-0003, Retail R1): an item says whether its
| stock carries an expiry date; the shared engine sells undated stock after
| dated stock and never treats it as expired.
*/

beforeEach(function () {
    $this->stock = app(Stock::class);
    $this->user = User::factory()->create();
    $this->juba = $this->platform()->branch('acme', 'Juba');
    $this->today = CarbonImmutable::parse('2026-10-19');
    // Not a medicine and no expiry: a shop's goods.
    $this->soap = $this->asTenant('acme', fn () => Item::factory()->create(['kind' => 'test', 'name' => 'Soap', 'tracks_expiry' => false]));
    $this->dated = $this->asTenant('acme', fn () => Item::factory()->create(['kind' => 'test', 'name' => 'Milk']));
});

function addStock(Item $item, string $batch, ?string $expires, int $quantity = 10): Batch
{
    return test()->asTenant('acme', fn () => test()->stock->add(
        $item, test()->juba, $batch, $expires === null ? null : CarbonImmutable::parse($expires), $quantity, 100, test()->user,
    ));
}

it('keeps stock of an item that does not track expiry without a date, and never as expired', function () {
    $batch = addStock($this->soap, 'S-1', null);

    expect($batch->expires_on)->toBeNull()
        ->and($batch->isExpired($this->today->addYears(50)))->toBeFalse()
        ->and($this->asTenant('acme', fn () => $this->stock->sellable($this->soap, $this->juba, $this->today->addYears(50))))->toBe(10);
});

it('refuses a date for an undated item and no date for a dated one', function () {
    expect(fn () => addStock($this->soap, 'S-1', '2027-01-31'))->toThrow(ValidationException::class, 'does not track expiry dates')
        ->and(fn () => addStock($this->dated, 'M-1', null))->toThrow(ValidationException::class, 'needs an expiry date');

    expect($this->asTenant('acme', fn () => Batch::query()->count()))->toBe(0);
});

it('adds to an undated batch, and refuses a dated batch under the same number', function () {
    addStock($this->soap, 'S-1', null, 4);
    expect(addStock($this->soap, 'S-1', null, 6)->quantity_on_hand)->toBe(10);

    $this->asTenant('acme', fn () => $this->dated->forceFill(['tracks_expiry' => false])->save());
    addStock($this->dated, 'M-1', null);
    $this->asTenant('acme', fn () => $this->dated->forceFill(['tracks_expiry' => true])->save());

    expect(fn () => addStock($this->dated, 'M-1', '2027-01-31'))->toThrow(ValidationException::class, 'with expiry none');
});

it('sells dated stock first, soonest expiry first, then undated stock', function () {
    // The same item, with undated stock from before it tracked expiry.
    $this->asTenant('acme', fn () => $this->dated->forceFill(['tracks_expiry' => false])->save());
    addStock($this->dated, 'A-OLD', null, 5);
    $this->asTenant('acme', fn () => $this->dated->forceFill(['tracks_expiry' => true])->save());
    addStock($this->dated, 'Z-LATE', '2027-06-30', 3);
    addStock($this->dated, 'B-SOON', '2026-12-31', 2);
    addStock($this->dated, 'C-GONE', '2026-10-19', 50);   // expires today: never sold

    $taken = $this->asTenant('acme', fn () => $this->stock->take($this->dated, $this->juba, 7, $this->user, 'sale', (string) Str::uuid(), $this->today));

    expect(array_map(fn ($part) => [$part['batch']->batch_number, $part['quantity']], $taken))
        ->toBe([['B-SOON', 2], ['Z-LATE', 3], ['A-OLD', 2]]);
});

it('receives undated goods, keyed to the line that is wrong', function () {
    // Bought by the industry served here (the pharmacy, in tests).
    config(['industries.pharmacy.item_kinds' => ['medicine', 'test']]);
    $supplier = $this->asTenant('acme', fn () => Supplier::query()->create(['name' => 'Wholesale Ltd']));
    $receive = fn (array $line) => $this->asTenant('acme', fn () => app(ReceiveGoods::class)->handle(
        $this->user, $this->juba, $supplier, null, null, $this->today,
        [['item_id' => $this->dated->id, 'batch_number' => 'M-1', 'expires_on' => '2027-01-31', 'quantity' => 1, 'unit_cost_minor' => 100], $line],
    ));

    expect(fn () => $receive(['item_id' => $this->soap->id, 'batch_number' => 'S-1', 'expires_on' => '2027-01-31', 'quantity' => 2, 'unit_cost_minor' => 50]))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey('lines.1.expires_on'));

    $receipt = $receive(['item_id' => $this->soap->id, 'batch_number' => 'S-1', 'expires_on' => null, 'quantity' => 2, 'unit_cost_minor' => 50]);

    expect($receipt->lines()->where('item_id', $this->soap->id)->value('expires_on'))->toBeNull()
        ->and($this->asTenant('acme', fn () => $this->stock->sellable($this->soap, $this->juba, $this->today)))->toBe(2);
});

it('sells only the items of the industry being served, and records the sale as its own', function () {
    $medicine = $this->asTenant('acme', fn () => Medicine::factory()->create(['name' => 'Panadol']));
    $this->asTenant('acme', fn () => $this->stock->add($medicine->item, $this->juba, 'P-1', CarbonImmutable::parse('2027-12-31'), 5, 100, $this->user));
    addStock($this->soap, 'S-1', null);
    $this->openTill($this->user, $this->juba);
    $sell = fn (string $itemId) => $this->asTenant('acme', fn () => app(Sell::class)->handle(
        $this->user, $this->juba, [['item_id' => $itemId, 'quantity' => 1]], PaymentMethod::Card,
    ));

    // The pharmacy (the primary application here) sells medicines only.
    expect(fn () => $sell($this->soap->id))->toThrow(ValidationException::class, 'This item cannot be sold.');

    $sale = $sell($medicine->id);
    expect($sale->application)->toBe('pharmacy')
        ->and($this->asTenant('acme', fn () => Sale::query()->count()))->toBe(1);
});
