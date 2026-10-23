<?php

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Inventory\Actions\Stock;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\StockDisposal;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Pharmacy\Models\Medicine;
use App\Modules\Sales\Models\Sale;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18 08:00'));
    $this->juba = $this->platform()->branch('acme', 'Juba');
    $this->wau = $this->platform()->branch('acme', 'Wau');
    $this->asTenant('acme', fn () => Workspace::query()->create(['slug' => 'acme', 'display_name' => 'Acme', 'currency' => 'SSP', 'timezone' => 'Africa/Juba']));

    $this->para = $this->asTenant('acme', fn () => Medicine::factory()->create(['sell_price_minor' => 250]));
    $owner = User::factory()->create();
    // The older batch would be sold first.
    $this->early = $this->asTenant('acme', fn () => app(Stock::class)->add($this->para->item, $this->juba, 'P-EARLY', CarbonImmutable::parse('2027-01-31'), 10, 100, $owner));
    $this->late = $this->asTenant('acme', fn () => app(Stock::class)->add($this->para->item, $this->juba, 'P-LATE', CarbonImmutable::parse('2027-12-31'), 10, 100, $owner));
});

function batchNamed(string $number): Batch
{
    return test()->asTenant('acme', fn () => Batch::query()->where('batch_number', $number)->sole());
}

describe('holds', function () {
    it('never sells a quarantined batch, and sells it again once released', function () {
        $this->actingAsMember('acme', ['pharmacy.stock.view', 'pharmacy.stock.hold'], branch: $this->juba);
        $this->post($this->workspaceUrl('acme', "batches/{$this->early->id}/hold"), ['status' => 'quarantined', 'reason' => 'Discoloured tablets'])
            ->assertSessionHasNoErrors();

        expect(batchNamed('P-EARLY'))->status->toBe(Batch::QUARANTINED)->hold_reason->toBe('Discoloured tablets');
        $this->get($this->workspaceUrl('acme', 'stock'))->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.sellable', 10)
            ->where('rows.0.held', 10)
            ->where('rows.0.nextExpiry', '2027-12-31'));

        $cashier = $this->actingAsMember('acme', ['pharmacy.sales.view', 'pharmacy.sales.create'], branch: $this->juba);
        $this->openTill($cashier, $this->juba);
        $this->get($this->workspaceUrl('acme', 'sales/create'))->assertInertia(fn (Assert $page) => $page->where('items.0.available', 10));
        $this->post($this->workspaceUrl('acme', 'sales'), [
            'items' => [['item_id' => $this->para->id, 'quantity' => 11]],
            'payment_method' => 'mobile_money',
        ])->assertSessionHasErrors('items.0.quantity');
        $this->post($this->workspaceUrl('acme', 'sales'), [
            'items' => [['item_id' => $this->para->id, 'quantity' => 4]],
            'payment_method' => 'mobile_money',
        ])->assertSessionHasNoErrors();

        // Sold from the later batch although the earlier one expires first.
        expect(batchNamed('P-EARLY')->quantity_on_hand)->toBe(10)
            ->and(batchNamed('P-LATE')->quantity_on_hand)->toBe(6);

        $this->actingAsMember('acme', ['pharmacy.stock.view', 'pharmacy.stock.hold'], branch: $this->juba);
        $this->post($this->workspaceUrl('acme', "batches/{$this->early->id}/release"))->assertSessionHasNoErrors();
        expect(batchNamed('P-EARLY'))->status->toBe(Batch::AVAILABLE)->hold_reason->toBeNull();
    });

    it('keeps a recalled batch out of sale for good', function () {
        $this->actingAsMember('acme', ['pharmacy.stock.view', 'pharmacy.stock.hold'], branch: $this->juba);
        $this->post($this->workspaceUrl('acme', "batches/{$this->early->id}/hold"), ['status' => 'recalled', 'reason' => 'Maker recall R-2026-14'])
            ->assertSessionHasNoErrors();

        $this->post($this->workspaceUrl('acme', "batches/{$this->early->id}/release"))->assertSessionHasErrors('hold');
        $this->post($this->workspaceUrl('acme', "batches/{$this->early->id}/hold"), ['status' => 'quarantined', 'reason' => 'x'])->assertSessionHasErrors('hold');

        expect(batchNamed('P-EARLY')->status)->toBe(Batch::RECALLED);
        $this->asTenant('acme', fn () => expect(app(Stock::class)->sellable($this->para->item, $this->juba))->toBe(10));
    });

    it('holds only with the permission, and only batches of this branch', function () {
        $this->actingAsMember('acme', ['pharmacy.stock.view', 'pharmacy.stock.adjust'], branch: $this->juba);
        $this->post($this->workspaceUrl('acme', "batches/{$this->early->id}/hold"), ['status' => 'quarantined', 'reason' => 'x'])->assertForbidden();

        $wauBatch = $this->asTenant('acme', fn () => app(Stock::class)->add($this->para->item, $this->wau, 'W-1', CarbonImmutable::parse('2027-06-30'), 5, 100, User::factory()->create()));
        $this->actingAsMember('acme', ['pharmacy.stock.view', 'pharmacy.stock.hold'], branch: $this->juba);
        $this->post($this->workspaceUrl('acme', "batches/{$wauBatch->id}/hold"), ['status' => 'quarantined', 'reason' => 'x'])->assertNotFound();
    });
});

describe('disposals', function () {
    it('writes stock off only once someone else approves', function () {
        $storekeeper = $this->actingAsMember('acme', ['pharmacy.stock.view', 'pharmacy.stock.adjust'], branch: $this->juba);
        $this->post($this->workspaceUrl('acme', "batches/{$this->early->id}/disposals"), ['quantity' => 3, 'reason' => 'damaged', 'note' => 'Crushed in delivery'])
            ->assertSessionHasNoErrors();
        // What is already asked for cannot be asked for twice.
        $this->post($this->workspaceUrl('acme', "batches/{$this->early->id}/disposals"), ['quantity' => 8, 'reason' => 'damaged'])
            ->assertSessionHasErrors('quantity');

        $disposal = $this->asTenant('acme', fn () => StockDisposal::query()->sole());
        expect($disposal)->number->toBe('D-00001')->status->toBe(StockDisposal::PENDING)
            ->and(batchNamed('P-EARLY')->quantity_on_hand)->toBe(10);

        // Asking does not let you decide.
        $this->post($this->workspaceUrl('acme', "disposals/{$disposal->id}/approve"))->assertForbidden();

        $manager = $this->actingAsMember('acme', ['pharmacy.stock.view', 'pharmacy.stock.adjust', 'pharmacy.stock.dispose'], branch: $this->juba);
        $this->get($this->workspaceUrl('acme', 'disposals'))->assertInertia(fn (Assert $page) => $page
            ->component('disposals/index')
            ->where('disposals.0.number', 'D-00001')
            ->where('disposals.0.costMinor', 300)
            ->where('canDecide', true));
        $this->post($this->workspaceUrl('acme', "disposals/{$disposal->id}/approve"), ['note' => 'Seen the boxes'])->assertSessionHasNoErrors();
        $this->post($this->workspaceUrl('acme', "disposals/{$disposal->id}/approve"))->assertSessionHasErrors('disposal');

        expect(batchNamed('P-EARLY')->quantity_on_hand)->toBe(7)
            ->and($disposal->refresh())->status->toBe(StockDisposal::APPROVED)->decided_by->toBe($manager->id);

        $movement = $this->asTenant('acme', fn () => StockMovement::query()->where('type', 'disposal')->sole());
        expect($movement)->quantity->toBe(-3)->reason->toBe('damaged')->reference_id->toBe($disposal->id)->user_id->toBe($manager->id)
            ->and($movement->user_id)->not->toBe($storekeeper->id);
    });

    it('lets nobody approve their own disposal', function () {
        $manager = $this->actingAsMember('acme', ['pharmacy.stock.view', 'pharmacy.stock.adjust', 'pharmacy.stock.dispose'], branch: $this->juba);
        $this->post($this->workspaceUrl('acme', "batches/{$this->early->id}/disposals"), ['quantity' => 2, 'reason' => 'expired']);
        $disposal = $this->asTenant('acme', fn () => StockDisposal::query()->sole());

        $this->get($this->workspaceUrl('acme', 'disposals'))->assertInertia(fn (Assert $page) => $page->where('disposals.0.mine', true));
        $this->post($this->workspaceUrl('acme', "disposals/{$disposal->id}/approve"))->assertSessionHasErrors('disposal');
        $this->post($this->workspaceUrl('acme', "disposals/{$disposal->id}/reject"), ['note' => 'Changed my mind'])->assertSessionHasErrors('disposal');

        expect(batchNamed('P-EARLY')->quantity_on_hand)->toBe(10)
            ->and($disposal->refresh()->status)->toBe(StockDisposal::PENDING);
    });

    it('keeps the stock when a disposal is turned down', function () {
        $this->actingAsMember('acme', ['pharmacy.stock.view', 'pharmacy.stock.adjust'], branch: $this->juba);
        $this->post($this->workspaceUrl('acme', "batches/{$this->early->id}/disposals"), ['quantity' => 2, 'reason' => 'other'])->assertSessionHasErrors('note');
        $this->post($this->workspaceUrl('acme', "batches/{$this->early->id}/disposals"), ['quantity' => 2, 'reason' => 'damaged']);
        $disposal = $this->asTenant('acme', fn () => StockDisposal::query()->sole());

        $this->actingAsMember('acme', ['pharmacy.stock.view', 'pharmacy.stock.dispose'], branch: $this->juba);
        $this->post($this->workspaceUrl('acme', "disposals/{$disposal->id}/reject"))->assertSessionHasErrors('note');
        $this->post($this->workspaceUrl('acme', "disposals/{$disposal->id}/reject"), ['note' => 'Boxes are fine'])->assertSessionHasNoErrors();

        expect(batchNamed('P-EARLY')->quantity_on_hand)->toBe(10)
            ->and($disposal->refresh())->status->toBe(StockDisposal::REJECTED)->decision_note->toBe('Boxes are fine');
    });

    it('sends damaged and expired write-offs through a disposal, not an adjustment', function () {
        $this->actingAsMember('acme', ['pharmacy.stock.view', 'pharmacy.stock.adjust'], branch: $this->juba);

        $this->post($this->workspaceUrl('acme', "batches/{$this->early->id}/adjustments"), ['change' => -2, 'reason' => 'damaged'])
            ->assertSessionHasErrors('change');
        $this->post($this->workspaceUrl('acme', "batches/{$this->early->id}/adjustments"), ['change' => -2, 'reason' => 'expired'])
            ->assertSessionHasErrors('change');

        expect(batchNamed('P-EARLY')->quantity_on_hand)->toBe(10)
            ->and($this->asTenant('acme', fn () => Sale::query()->count()))->toBe(0);
    });
});
