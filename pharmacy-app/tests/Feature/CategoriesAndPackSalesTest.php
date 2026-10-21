<?php

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Inventory\Actions\Stock;
use App\Modules\Inventory\Enums\StorageCondition;
use App\Modules\Inventory\Models\ItemPack;
use App\Modules\Pharmacy\Models\Medicine;
use App\Modules\Sales\Models\Sale;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Miles\SharedModuleKit\Envelope;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18 08:00'));
    $this->juba = $this->platform()->branch('acme', 'Juba');
    $this->asTenant('acme', fn () => Workspace::query()->create(['slug' => 'acme', 'display_name' => 'Acme', 'currency' => 'SSP', 'timezone' => 'Africa/Juba']));
});

describe('categories and storage', function () {
    it('keeps a category (one spelling) and how the medicine is stored', function () {
        $this->actingAsMember('acme', ['pharmacy.medicines.view', 'pharmacy.medicines.manage']);
        $medicine = fn (string $name, string $category, ?string $storage = null) => $this->post($this->workspaceUrl('acme', 'medicines'), array_filter([
            'name' => $name, 'generic_name' => strtolower($name), 'form' => 'tablet', 'unit' => 'tablet',
            'sell_price' => '2.50', 'reorder_level' => 10, 'category' => $category, 'storage' => $storage,
        ]))->assertSessionHasNoErrors();

        $medicine('Amoxil', 'Antibiotics');
        $medicine('Cipro', ' antibiotics ');
        $medicine('Insulin', 'Diabetes', 'refrigerated');

        $saved = $this->asTenant('acme', fn () => Medicine::query()->get()->keyBy('name'));
        expect($saved['Cipro']->category)->toBe('Antibiotics')
            ->and($saved['Amoxil']->storage)->toBe(StorageCondition::Room)
            ->and($saved['Insulin']->storage)->toBe(StorageCondition::Refrigerated)
            ->and($saved['Insulin']->item->storage)->toBe(StorageCondition::Refrigerated);

        $this->get($this->workspaceUrl('acme', 'medicines?category=Antibiotics'))->assertInertia(fn (Assert $page) => $page
            ->where('categories', ['Antibiotics', 'Diabetes'])
            ->has('medicines', 2)
            ->where('medicines.0.category', 'Antibiotics'));
        $this->get($this->workspaceUrl('acme', 'medicines?category=Diabetes'))->assertInertia(fn (Assert $page) => $page
            ->where('medicines.0.storageLabel', 'Refrigerate'));

        $this->post($this->workspaceUrl('acme', 'medicines'), [
            'name' => 'X', 'generic_name' => 'x', 'form' => 'tablet', 'unit' => 'tablet', 'sell_price' => '1', 'reorder_level' => 0, 'storage' => 'warm',
        ])->assertSessionHasErrors('storage');
    });
});

describe('selling packs', function () {
    beforeEach(function () {
        $this->asTenant('acme', function () {
            $keeper = User::factory()->create();
            $this->para = Medicine::factory()->create(['sell_price_minor' => 250, 'category' => 'Pain relief', 'storage' => 'cool']);
            $this->box = ItemPack::query()->create(['item_id' => $this->para->id, 'name' => 'box', 'units' => 10, 'sell_price_minor' => 2000]);
            $this->strip = ItemPack::query()->create(['item_id' => $this->para->id, 'name' => 'strip', 'units' => 4]);
            app(Stock::class)->add($this->para->item, $this->juba, 'P-1', CarbonImmutable::parse('2027-01-31'), 15, 100, $keeper);
            app(Stock::class)->add($this->para->item, $this->juba, 'P-2', CarbonImmutable::parse('2027-06-30'), 30, 120, $keeper);
        });

        $this->seller = $this->actingAsMember('acme', ['pharmacy.sales.view', 'pharmacy.sales.create', 'pharmacy.sales.discount', 'pharmacy.sales.void'], branch: $this->juba);
        $this->openTill($this->seller, $this->juba);
    });

    it('offers the packs at the counter with their prices', function () {
        $this->get($this->workspaceUrl('acme', 'sales/create'))->assertInertia(fn (Assert $page) => $page
            ->where('medicines.0.category', 'Pain relief')
            ->where('medicines.0.storage', 'Keep cool')
            ->where('medicines.0.packs', fn ($packs) => collect($packs)->sortBy('units')->map(fn ($pack) => [$pack['name'], $pack['units'], $pack['priceMinor']])->values()->all() === [
                ['strip', 4, 1000],
                ['box', 10, 2000],
            ]));
    });

    it('sells by the pack at the pack price, spread over the batches it comes from', function () {
        $this->post($this->workspaceUrl('acme', 'sales'), [
            'items' => [['item_id' => $this->para->id, 'quantity' => 2, 'pack_id' => $this->box->id, 'discount_percent' => 10]],
            'payment_method' => 'mobile_money',
        ])->assertSessionHasNoErrors();

        $sale = $this->asTenant('acme', fn () => Sale::query()->with('lines')->sole());
        // 2 boxes of 10 at 20.00, less 10%: 36.00 for 20 tablets (15 from P-1, 5 from P-2).
        expect($sale->total_minor)->toBe(3600)
            ->and($sale->discount_minor)->toBe(400)
            ->and($sale->lines->map(fn ($line) => [$line->quantity, $line->discount_minor, $line->line_total_minor, $line->pack_name, $line->units_per_pack, $line->pack_price_minor])->all())
            ->toBe([[15, 300, 2700, 'box', 10, 2000], [5, 100, 900, 'box', 10, 2000]]);

        $invoice = Envelope::fromArray($this->platform()->moduleEvents['finance'][0]);
        expect($invoice->payload['lines'][0])
            ->description->toBe("{$this->para->label()} (2 box of 10)")
            ->quantity->toBe(20)
            ->amount_minor->toBe(3600)
            ->cost_minor->toBe(15 * 100 + 5 * 120);

        $this->get($this->workspaceUrl('acme', "sales/{$sale->id}/receipt"))->assertInertia(fn (Assert $page) => $page
            ->where('document.lines.0.pack', 'box of 10')
            ->where('document.lines.0.quantity', 2)
            ->where('document.lines.0.unitPriceMinor', 2000)
            ->where('document.lines.0.lineTotalMinor', 3600));

        // Returns stay in tablets, at what they were sold for.
        $this->post($this->workspaceUrl('acme', "sales/{$sale->id}/returns"), [
            'reason' => 'Opened box',
            'lines' => [['sale_line_id' => $sale->lines[0]->id, 'quantity' => 5, 'restock' => true]],
        ])->assertSessionHasNoErrors();
        expect($this->asTenant('acme', fn () => (int) $sale->returns()->sum('total_minor')))->toBe(900);
    });

    it('charges a pack without its own price at its units', function () {
        $this->post($this->workspaceUrl('acme', 'sales'), [
            'items' => [['item_id' => $this->para->id, 'quantity' => 3, 'pack_id' => $this->strip->id]],
            'payment_method' => 'cash',
            'amount_tendered' => '50.00',
        ])->assertSessionHasNoErrors();

        expect($this->asTenant('acme', fn () => Sale::query()->sole()->total_minor))->toBe(3 * 4 * 250);
    });

    it('refuses another item\'s pack, and more packs than there is stock for', function () {
        $other = $this->asTenant('acme', function () {
            $medicine = Medicine::factory()->create();

            return ItemPack::query()->create(['item_id' => $medicine->id, 'name' => 'box', 'units' => 10]);
        });

        $this->post($this->workspaceUrl('acme', 'sales'), [
            'items' => [['item_id' => $this->para->id, 'quantity' => 1, 'pack_id' => $other->id]],
            'payment_method' => 'mobile_money',
        ])->assertSessionHasErrors('items.0.pack_id');
        $this->post($this->workspaceUrl('acme', 'sales'), [
            'items' => [['item_id' => $this->para->id, 'quantity' => 5, 'pack_id' => $this->box->id]],
            'payment_method' => 'mobile_money',
        ])->assertSessionHasErrors('items.0.quantity');

        expect($this->asTenant('acme', fn () => Sale::query()->count()))->toBe(0);
    });
});
