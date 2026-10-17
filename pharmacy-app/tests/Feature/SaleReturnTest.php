<?php

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Inventory\Actions\Stock;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Pharmacy\Models\Medicine;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleReturn;
use App\Modules\Sales\Models\TillSession;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Miles\SharedModuleKit\Envelope;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18 08:00'));
    $this->juba = $this->platform()->branch('acme', 'Juba');
    $this->asTenant('acme', fn () => Workspace::query()->create(['slug' => 'acme', 'display_name' => 'Acme', 'currency' => 'SSP', 'timezone' => 'Africa/Juba']));

    $this->amox = $this->asTenant('acme', fn () => Medicine::factory()->create(['sell_price_minor' => 333]));
    $this->asTenant('acme', fn () => app(Stock::class)->add($this->amox->item, $this->juba, 'A-1', CarbonImmutable::parse('2027-12-31'), 10, 100, User::factory()->create()));

    $this->manager = $this->actingAsMember('acme', ['pharmacy.sales.view', 'pharmacy.sales.create', 'pharmacy.sales.discount', 'pharmacy.sales.void', 'pharmacy.reports.view'], branch: $this->juba);
    $this->openTill($this->manager, $this->juba);

    // 3 × 3.33 less 10% (1.00): 8.99 in cash.
    $this->post($this->workspaceUrl('acme', 'sales'), [
        'items' => [['item_id' => $this->amox->id, 'quantity' => 3, 'discount_percent' => 10]],
        'payment_method' => 'cash',
        'amount_tendered' => '10.00',
    ])->assertSessionHasNoErrors();
    $this->sale = $this->asTenant('acme', fn () => Sale::query()->with('lines')->sole());
});

function returnLines(int $quantity, bool $restock = true): array
{
    return ['sale_line_id' => test()->sale->lines->sole()->id, 'quantity' => $quantity, 'restock' => $restock];
}

function returnedBatchOnHand(): int
{
    return test()->asTenant('acme', fn () => (int) Batch::query()->where('batch_number', 'A-1')->value('quantity_on_hand'));
}

it('returns a sale in parts that add up to exactly what was paid', function () {
    $this->post($this->workspaceUrl('acme', "sales/{$this->sale->id}/returns"), [
        'reason' => 'Allergic reaction',
        'lines' => [returnLines(1)],
    ])->assertSessionHasNoErrors();
    $this->post($this->workspaceUrl('acme', "sales/{$this->sale->id}/returns"), [
        'reason' => 'Packaging opened',
        'lines' => [returnLines(2, restock: false)],
    ])->assertSessionHasNoErrors();

    // 8.99 for three: 3.00 for the first, the last two take the rest.
    $returns = $this->asTenant('acme', fn () => SaleReturn::query()->with('lines')->orderBy('number')->get());
    expect($returns->pluck('total_minor')->all())->toBe([300, 599])
        ->and($returns->pluck('number')->all())->toBe(['R-000001', 'R-000002'])
        ->and(returnedBatchOnHand())->toBe(8);

    [, , $first, $second] = $this->platform()->moduleEvents['finance']; // after the invoice and payment
    $first = Envelope::fromArray($first);
    $second = Envelope::fromArray($second);
    expect($first->eventType)->toBe('finance.sales_return.issued')
        ->and($first->payload['invoice_number'])->toBe($this->sale->number)
        ->and($first->payload['lines'][0]['cost_minor'])->toBe(100)
        ->and($first->payload['refund'])->toBe(['method' => 'cash', 'amount_minor' => 300, 'payment_reference' => 'R-000001'])
        ->and($second->payload['total_minor'])->toBe(599)
        ->and($second->payload['lines'][0]['cost_minor'])->toBe(0);

    $this->get($this->workspaceUrl('acme', "sales/{$this->sale->id}"))->assertInertia(fn (Assert $page) => $page
        ->where('sale.lines.0.returned', 3)
        ->has('sale.returns', 2)
        ->where('canReturn', false)
        ->where('canVoid', false));
});

it('returns no more than what is left on the line', function () {
    $this->post($this->workspaceUrl('acme', "sales/{$this->sale->id}/returns"), ['reason' => 'Wrong', 'lines' => [returnLines(2)]])
        ->assertSessionHasNoErrors();
    $this->post($this->workspaceUrl('acme', "sales/{$this->sale->id}/returns"), ['reason' => 'Wrong', 'lines' => [returnLines(2)]])
        ->assertSessionHasErrors('lines.0.quantity');
    $this->post($this->workspaceUrl('acme', "sales/{$this->sale->id}/returns"), ['reason' => 'Wrong', 'lines' => [returnLines(0)]])
        ->assertSessionHasErrors('lines');

    expect($this->asTenant('acme', fn () => SaleReturn::query()->count()))->toBe(1);
});

it('finishes a partly returned sale with a return, not a void', function () {
    $this->post($this->workspaceUrl('acme', "sales/{$this->sale->id}/returns"), ['reason' => 'Wrong', 'lines' => [returnLines(1)]]);

    $this->post($this->workspaceUrl('acme', "sales/{$this->sale->id}/void"), ['reason' => 'Changed mind'])
        ->assertSessionHasErrors('reason');

    expect($this->asTenant('acme', fn () => Sale::query()->sole()->status))->toBe(Sale::COMPLETED);
});

it('refunds cash from the open till of whoever takes the return', function () {
    $till = $this->asTenant('acme', fn () => TillSession::query()->sole());
    $this->asTenant('acme', fn () => $till->forceFill(['status' => TillSession::CLOSED, 'closed_at' => now()])->save());

    $this->post($this->workspaceUrl('acme', "sales/{$this->sale->id}/returns"), ['reason' => 'Wrong', 'lines' => [returnLines(1)]])
        ->assertSessionHasErrors('lines');

    $open = $this->openTill($this->manager, $this->juba, 1000);
    $this->post($this->workspaceUrl('acme', "sales/{$this->sale->id}/returns"), ['reason' => 'Wrong', 'lines' => [returnLines(1)]])
        ->assertSessionHasNoErrors();

    $this->get($this->workspaceUrl('acme', "tills/{$open->id}"))->assertInertia(fn (Assert $page) => $page
        ->where('summary.cashRefundsMinor', 300)
        ->where('summary.expectedCashMinor', 700));
});

it('keeps returns off the takings and restocked goods off the cost', function () {
    $this->post($this->workspaceUrl('acme', "sales/{$this->sale->id}/returns"), ['reason' => 'Wrong', 'lines' => [returnLines(1)]]);

    $this->get($this->workspaceUrl('acme', 'sales'))->assertInertia(fn (Assert $page) => $page
        ->where('takings.totalMinor', 599)
        ->where('takings.returnsMinor', 300));
    $this->get($this->workspaceUrl('acme', 'reports'))->assertInertia(fn (Assert $page) => $page
        ->where('summary.revenueMinor', 599)
        ->where('summary.costMinor', 200)
        ->where('summary.returnedCount', 1)
        ->where('summary.returnedMinor', 300));
});

it('takes returns only from members who may refund', function () {
    $this->actingAsMember('acme', ['pharmacy.sales.view', 'pharmacy.sales.create'], branch: $this->juba);

    $this->post($this->workspaceUrl('acme', "sales/{$this->sale->id}/returns"), ['reason' => 'Wrong', 'lines' => [returnLines(1)]])
        ->assertForbidden();
});
