<?php

use App\Models\Batch;
use App\Models\Medicine;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Workspace;
use App\Pharmacy\Stock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Miles\SharedModuleKit\Envelope;

beforeEach(function () {
    config(['platform-client.modules.finance' => 'https://finance.internal/events']);
    $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00'));
    $this->juba = $this->platform()->branch('acme', 'Juba');
    $this->wau = $this->platform()->branch('acme', 'Wau');
    $this->asTenant('acme', fn () => Workspace::query()->create(['slug' => 'acme', 'display_name' => 'Acme', 'currency' => 'SSP', 'timezone' => 'Africa/Juba']));

    $this->para = $this->asTenant('acme', fn () => Medicine::factory()->create(['name' => 'Panadol', 'sell_price_minor' => 250]));
    $this->amox = $this->asTenant('acme', fn () => Medicine::factory()->create(['name' => 'Amoxil', 'prescription_only' => true, 'sell_price_minor' => 1200]));

    $stock = app(Stock::class);
    $keeper = User::factory()->create();
    $this->asTenant('acme', function () use ($stock, $keeper) {
        $stock->add($this->para, $this->juba, 'P-SOON', CarbonImmutable::parse('2026-12-31'), 3, 100, $keeper);
        $stock->add($this->para, $this->juba, 'P-LATE', CarbonImmutable::parse('2027-12-31'), 20, 100, $keeper);
        $stock->add($this->amox, $this->juba, 'A-1', CarbonImmutable::parse('2027-06-30'), 20, 800, $keeper);
    });
});

function cashier(string $branch, array $extra = []): void
{
    test()->actingAsMember('acme', ['pharmacy.sales.create', 'pharmacy.sales.view', ...$extra], branch: $branch);
}

function sell(array $items, string $method = 'cash', ?string $tendered = '50.00')
{
    return test()->post(test()->workspaceUrl('acme', 'sales'), array_filter([
        'items' => $items,
        'payment_method' => $method,
        'amount_tendered' => $tendered,
        'customer_name' => 'Walk-in',
    ], fn ($value) => $value !== null));
}

function onHand(string $batch): int
{
    return test()->asTenant('acme', fn () => (int) Batch::query()->where('batch_number', $batch)->value('quantity_on_hand'));
}

it('sells oldest expiry first, gives change, and reports the sale to Finance', function () {
    cashier($this->juba);

    sell([['medicine_id' => $this->para->id, 'quantity' => 5]], tendered: '20.00')->assertSessionHasNoErrors();

    $sale = $this->asTenant('acme', fn () => Sale::query()->with('lines')->sole());
    expect($sale)
        ->number->toBe('S-000001')
        ->total_minor->toBe(1250)
        ->change_minor->toBe(750)
        ->currency->toBe('SSP')
        ->and($sale->lines->map(fn ($line) => [$line->quantity, $line->unit_price_minor])->all())->toBe([[3, 250], [2, 250]])
        ->and(onHand('P-SOON'))->toBe(0)
        ->and(onHand('P-LATE'))->toBe(18);

    $movements = $this->asTenant('acme', fn () => StockMovement::query()->where('reference_id', $sale->id)->pluck('quantity')->sort()->values()->all());
    expect($movements)->toBe([-3, -2]);

    [$invoice, $payment] = array_map(fn ($event) => Envelope::fromArray($event), $this->platform()->moduleEvents['finance']);
    expect($invoice->eventType)->toBe('finance.invoice.issued')
        ->and($invoice->sourceTransactionId)->toBe("sale:{$sale->id}:invoice")
        ->and($invoice->payload)->toMatchArray(['invoice_number' => 'S-000001', 'total_minor' => 1250, 'currency' => 'SSP', 'branch_id' => $this->juba])
        ->and($invoice->payload['lines'])->toBe([['description' => $this->para->label(), 'quantity' => 5, 'amount_minor' => 1250, 'revenue_account' => null]])
        ->and($payment->eventType)->toBe('finance.payment.received')
        ->and($payment->payload)->toMatchArray(['amount_minor' => 1250, 'method' => 'cash', 'invoice_number' => 'S-000001']);
});

it('changes nothing when the sale cannot go through', function (array $items, ?string $tendered, string $error) {
    cashier($this->juba);
    // Datasets are built before the medicines exist: names stand for them.
    $items = array_map(fn (array $item) => ['medicine_id' => $this->{$item['medicine_id']}->id, 'quantity' => $item['quantity']], $items);

    sell($items, tendered: $tendered)->assertSessionHasErrors($error);

    expect($this->asTenant('acme', fn () => Sale::query()->count()))->toBe(0)
        ->and(onHand('P-SOON') + onHand('P-LATE'))->toBe(23)
        ->and(DB::table('platform_outbox')->count())->toBe(0);
})->with([
    'not enough cash' => [[['medicine_id' => 'para', 'quantity' => 5]], '10.00', 'amount_tendered'],
    'not enough stock' => [[['medicine_id' => 'para', 'quantity' => 24]], '100.00', 'items.0.quantity'],
    'prescription only' => [[['medicine_id' => 'para', 'quantity' => 1], ['medicine_id' => 'amox', 'quantity' => 1]], '50.00', 'items.1.medicine_id'],
]);

it('takes mobile money without cash and keeps the reference', function () {
    cashier($this->juba);

    sell([['medicine_id' => $this->para->id, 'quantity' => 2]], 'mobile_money', null)->assertSessionHasNoErrors();
    $this->post($this->workspaceUrl('acme', 'sales'), [
        'items' => [['medicine_id' => $this->para->id, 'quantity' => 1]],
        'payment_method' => 'mobile_money',
        'payment_reference' => 'MTN-88231',
    ])->assertSessionHasNoErrors();

    $sale = $this->asTenant('acme', fn () => Sale::query()->where('number', 'S-000002')->sole());
    expect($sale)->payment_reference->toBe('MTN-88231')->amount_tendered_minor->toBeNull()->change_minor->toBeNull();
});

it('voids a sale: stock back to its batches, Finance told, only once', function () {
    cashier($this->juba, ['pharmacy.sales.void']);
    sell([['medicine_id' => $this->para->id, 'quantity' => 5]], tendered: '12.50');
    $sale = $this->asTenant('acme', fn () => Sale::query()->sole());

    $this->post($this->workspaceUrl('acme', "sales/{$sale->id}/void"), ['reason' => 'Wrong item'])->assertSessionHasNoErrors();

    expect($sale->refresh())->status->toBe(Sale::VOIDED)->void_reason->toBe('Wrong item')
        ->and(onHand('P-SOON'))->toBe(3)
        ->and(onHand('P-LATE'))->toBe(20);

    $void = Envelope::fromArray($this->platform()->moduleEvents['finance'][2]);
    expect($void->eventType)->toBe('finance.invoice.voided')
        ->and($void->payload)->toMatchArray([
            'invoice_number' => 'S-000001',
            'total_minor' => 1250,
            'reason' => 'Wrong item',
            'refund' => ['method' => 'cash', 'amount_minor' => 1250, 'payment_reference' => 'S-000001'],
        ]);

    $this->post($this->workspaceUrl('acme', "sales/{$sale->id}/void"), ['reason' => 'Again'])->assertSessionHasErrors('reason');
    expect(onHand('P-LATE'))->toBe(20);
});

it('needs the void permission in this branch, and never shows another branch\'s sales', function () {
    cashier($this->juba);
    sell([['medicine_id' => $this->para->id, 'quantity' => 1]], tendered: '2.50');
    $sale = $this->asTenant('acme', fn () => Sale::query()->sole());

    $this->post($this->workspaceUrl('acme', "sales/{$sale->id}/void"), ['reason' => 'Mine'])->assertForbidden();

    cashier($this->wau, ['pharmacy.sales.void']);
    $this->get($this->workspaceUrl('acme', "sales/{$sale->id}"))->assertNotFound();
    $this->post($this->workspaceUrl('acme', "sales/{$sale->id}/void"), ['reason' => 'Not mine'])->assertNotFound();
});

it('shows the counter, the day\'s takings without voided sales, and the receipt', function () {
    cashier($this->juba, ['pharmacy.sales.void']);
    sell([['medicine_id' => $this->para->id, 'quantity' => 2]], tendered: '5.00');
    sell([['medicine_id' => $this->para->id, 'quantity' => 1]], tendered: '2.50');
    $voided = $this->asTenant('acme', fn () => Sale::query()->where('number', 'S-000002')->sole());
    $this->post($this->workspaceUrl('acme', "sales/{$voided->id}/void"), ['reason' => 'Test']);

    $this->get($this->workspaceUrl('acme', 'sales/create'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('sales/create')
            ->where('medicines.0.label', $this->amox->label())
            ->where('medicines.0.prescriptionOnly', true)
            ->where('medicines.1.available', 21));

    $this->get($this->workspaceUrl('acme', 'sales'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('sales/index')
            ->has('sales', 2)
            ->where('takings.count', 1)
            ->where('takings.totalMinor', 500));

    $this->get($this->workspaceUrl('acme', "sales/{$voided->id}"))
        ->assertInertia(fn (Assert $page) => $page
            ->component('sales/show')
            ->where('sale.status', 'voided')
            ->where('canVoid', false));
});
