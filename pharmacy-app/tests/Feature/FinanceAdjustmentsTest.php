<?php

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Inventory\Actions\Stock;
use App\Modules\Inventory\Enums\AdjustmentReason;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\StockDisposal;
use App\Modules\Pharmacy\Models\Medicine;
use App\Modules\Sales\Models\TillSession;
use Carbon\CarbonImmutable;
use Miles\SharedModuleKit\Envelope;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18 08:00'));
    $this->juba = $this->platform()->branch('acme', 'Juba');
    $this->asTenant('acme', fn () => Workspace::query()->create(['slug' => 'acme', 'display_name' => 'Acme', 'currency' => 'SSP', 'timezone' => 'Africa/Juba']));
    $this->para = $this->asTenant('acme', fn () => Medicine::factory()->create(['name' => 'Paracetamol', 'sell_price_minor' => 250]));
});

/** @return list<Envelope> */
function financeEvents(string $type): array
{
    return collect(test()->platform()->moduleEvents['finance'] ?? [])
        ->map(fn ($event) => Envelope::fromArray($event))
        ->filter(fn (Envelope $event) => $event->eventType === $type)
        ->values()
        ->all();
}

it('sends opening stock and counts to Finance at cost', function () {
    $this->actingAsMember('acme', ['pharmacy.stock.view', 'pharmacy.stock.adjust'], branch: $this->juba);
    $this->post($this->workspaceUrl('acme', "stock/{$this->para->id}/opening"), [
        'batch_number' => 'P-1', 'expires_on' => '2027-06-30', 'quantity' => 20, 'unit_cost' => '1.50',
    ])->assertSessionHasNoErrors();

    $batch = $this->asTenant('acme', fn () => Batch::query()->sole());
    $this->asTenant('acme', fn () => app(Stock::class)->adjust($batch, -3, AdjustmentReason::CountCorrection, 'Shelf recount', User::factory()->create()));

    [$opening, $count] = financeEvents('finance.stock.adjusted');
    expect($opening->payload)
        ->kind->toBe('opening')
        ->amount_minor->toBe(3000)
        ->branch_id->toBe($this->juba)
        ->currency->toBe('SSP')
        ->adjusted_on->toBe('2026-10-18')
        ->and($opening->payload['lines'][0])->toBe(['description' => "{$this->para->item->name}, batch P-1", 'quantity' => 20, 'amount_minor' => 3000]);
    expect($count->payload)
        ->kind->toBe('count')
        ->amount_minor->toBe(-450)
        ->reason->toContain('Shelf recount');
});

it('writes approved disposals off under their number, and nothing for a delivery', function () {
    $batch = $this->asTenant('acme', fn () => app(Stock::class)->add($this->para->item, $this->juba, 'P-1', CarbonImmutable::parse('2027-06-30'), 10, 200, User::factory()->create()));
    expect(financeEvents('finance.stock.adjusted'))->toBeEmpty();

    $this->actingAsMember('acme', ['pharmacy.stock.view', 'pharmacy.stock.adjust'], branch: $this->juba);
    $this->post($this->workspaceUrl('acme', "batches/{$batch->id}/disposals"), ['quantity' => 4, 'reason' => 'damaged'])->assertSessionHasNoErrors();
    $disposal = $this->asTenant('acme', fn () => StockDisposal::query()->sole());

    $this->actingAsMember('acme', ['pharmacy.stock.view', 'pharmacy.stock.dispose'], branch: $this->juba);
    $this->post($this->workspaceUrl('acme', "disposals/{$disposal->id}/approve"))->assertSessionHasNoErrors();

    [$writeOff] = financeEvents('finance.stock.adjusted');
    expect($writeOff->payload)
        ->kind->toBe('write_off')
        ->reference->toBe($disposal->number)
        ->amount_minor->toBe(-800);
});

it('sends a till that was short to Finance, and nothing when it balances', function () {
    $cashier = $this->actingAsMember('acme', ['pharmacy.sales.view', 'pharmacy.sales.create'], branch: $this->juba);
    $this->openTill($cashier, $this->juba, 2000);
    $till = $this->asTenant('acme', fn () => TillSession::query()->sole());
    $this->post($this->workspaceUrl('acme', "tills/{$till->id}/close"), ['counted_cash' => '18.50', 'closing_note' => 'Coins missing'])->assertSessionHasNoErrors();

    [$counted] = financeEvents('finance.cash.counted');
    expect($counted->payload)
        ->reference->toBe($till->number)
        ->expected_minor->toBe(2000)
        ->counted_minor->toBe(1850)
        ->difference_minor->toBe(-150)
        ->note->toBe('Coins missing');

    $this->openTill($cashier, $this->juba, 1000);
    $second = $this->asTenant('acme', fn () => TillSession::query()->where('status', TillSession::OPEN)->sole());
    $this->post($this->workspaceUrl('acme', "tills/{$second->id}/close"), ['counted_cash' => '10.00'])->assertSessionHasNoErrors();
    expect(financeEvents('finance.cash.counted'))->toHaveCount(1);
});
