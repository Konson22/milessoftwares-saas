<?php

use App\Enums\ExpenseStatus;
use App\Models\AuditEvent;
use App\Models\Expense;
use App\Models\Funder;
use App\Models\Grant;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\PurchaseQuote;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestLine;
use App\Models\Supplier;
use App\Models\User;
use App\Models\UserNotification;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Procurement: a request is approved, quotes compared and one chosen, an order
| raised (which requests the spending), goods received, the invoice recorded,
| and finance pays. Every step held apart; budgets and grants count the order
| once, through the expense.
*/

const BUYER = ['projects.view', 'procurement.view', 'procurement.request', 'procurement.manage'];

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 09:00'));
    Storage::fake('local');
    $this->organization('acme');
    $this->requester = $this->member('acme', ['projects.view', 'procurement.view', 'procurement.request']);
    $this->approver = $this->member('acme', ['procurement.view', 'procurement.approve']);
    $this->buyer = $this->member('acme', BUYER);
    $this->finance = $this->member('acme', ['projects.view', 'projects.finance.view', 'finance.expenses.approve', 'finance.payments.record', 'procurement.view']);
    $this->project = procurementProject($this->buyer);
});

function procurementUrl(string $path = '', string $slug = 'acme'): string
{
    return test()->organizationUrl($slug, 'procurement'.($path === '' ? '' : "/{$path}"));
}

/**
 * Open, with an approved budget of 5,000 for materials.
 */
function procurementProject(User $by): Project
{
    return test()->asTenant('acme', function () use ($by) {
        $project = new Project(['code' => 'WASH', 'name' => 'Water', 'status' => 'active', 'currency' => 'USD', 'created_by' => $by->id]);
        $project->save();
        $version = $project->budgetVersions()->create(['number' => 1, 'status' => 'approved', 'created_by' => $by->id, 'total_minor' => 500000]);
        $version->lines()->create(['project_id' => $project->id, 'category' => 'materials', 'amount_minor' => 500000]);

        return $project;
    });
}

/**
 * @param  array<string, mixed>  $overrides
 */
function aPurchase(array $overrides = []): PurchaseRequest
{
    test()->actingAs(test()->requester);
    test()->post(procurementUrl(), [
        'project_id' => test()->project->id,
        'title' => 'Hand pumps',
        'justification' => 'Six boreholes need them.',
        'category' => 'materials',
        'lines' => [
            ['description' => 'Pump', 'quantity' => '4', 'unit' => 'each', 'unit_price' => '250.50'],
            ['description' => 'Pipe', 'quantity' => '10.5', 'unit' => 'm', 'unit_price' => '10'],
        ],
        ...$overrides,
    ])->assertSessionHasNoErrors()->assertRedirect();

    return test()->asTenant('acme', fn () => PurchaseRequest::query()->latest()->orderByDesc('number')->firstOrFail());
}

function anApprovedPurchase(): PurchaseRequest
{
    $purchase = aPurchase();
    test()->post(procurementUrl("{$purchase->id}/submit"))->assertSessionHasNoErrors();
    test()->actingAs(test()->approver);
    test()->post(procurementUrl("{$purchase->id}/approve"))->assertSessionHasNoErrors()->assertRedirect();

    return $purchase->fresh() ?? $purchase;
}

function aQuote(PurchaseRequest $purchase, string $supplier, string $amount, array $extra = []): PurchaseQuote
{
    test()->actingAs(test()->buyer);
    test()->post(procurementUrl("{$purchase->id}/quotes"), ['supplier_name' => $supplier, 'amount' => $amount, ...$extra])->assertSessionHasNoErrors()->assertRedirect();

    return test()->asTenant('acme', fn () => PurchaseQuote::query()->whereHas('supplier', fn ($q) => $q->where('name', $supplier))->where('purchase_request_id', $purchase->id)->firstOrFail());
}

/**
 * Three quotes, the lowest chosen, the order raised by the buyer.
 */
function anOrder(string $amount = '1000'): PurchaseOrder
{
    $purchase = anApprovedPurchase();
    $quote = aQuote($purchase, 'Pumps Ltd', $amount);
    aQuote($purchase, 'Water Co', '6000');
    aQuote($purchase, 'Juba Supply', '7000');
    test()->post(procurementUrl("{$purchase->id}/quotes/{$quote->id}/choose"))->assertSessionHasNoErrors();
    test()->post(procurementUrl("{$purchase->id}/orders"), ['terms' => 'Delivery to Juba'])->assertSessionHasNoErrors()->assertRedirect();

    return test()->asTenant('acme', fn () => PurchaseOrder::query()->where('purchase_request_id', $purchase->id)->with('expense')->firstOrFail());
}

function financeApproves(PurchaseOrder $order): void
{
    test()->actingAs(test()->finance);
    test()->post(test()->organizationUrl('acme', "projects/{$order->expense->project_id}/expenses/{$order->expense_id}/approve"))->assertSessionHasNoErrors()->assertRedirect();
}

/**
 * @return array<string, string> quantities for everything on the order
 */
function allLines(PurchaseOrder $order): array
{
    return test()->asTenant('acme', fn () => PurchaseRequestLine::query()->where('purchase_request_id', $order->purchase_request_id)->get()
        ->mapWithKeys(fn (PurchaseRequestLine $line) => [$line->id => (string) $line->quantity])->all());
}

function orderPath(PurchaseOrder $order, string $path): string
{
    return procurementUrl("{$order->purchase_request_id}/orders/{$order->id}/{$path}");
}

it('takes a purchase from the request to payment, with one commitment throughout', function () {
    $order = anOrder();

    // The order only asks for the money: nothing is committed yet.
    expect($order->expense->status)->toBe(ExpenseStatus::Requested)
        ->and($order->expense->amount_minor)->toBe(100000)
        ->and($order->expense->supplier)->toBe('Pumps Ltd')
        ->and($order->number)->toBe('PO-000001');

    $this->post(orderPath($order, 'receipts'), ['received_on' => '2026-11-04', 'quantities' => allLines($order)])->assertSessionHasErrors('receipt');

    financeApproves($order);
    $this->actingAs($this->buyer);
    $this->post(orderPath($order, 'receipts'), ['received_on' => '2026-11-04', 'quantities' => allLines($order)])->assertSessionHasNoErrors()->assertRedirect();
    $this->post(orderPath($order, 'invoice'), ['invoice_reference' => 'INV-77', 'invoice_on' => '2026-11-04'])->assertSessionHasNoErrors()->assertRedirect();

    $this->actingAs($this->finance);
    $this->post($this->organizationUrl('acme', "projects/{$this->project->id}/expenses/{$order->expense_id}/pay"), ['paid_on' => '2026-11-04', 'reference' => 'TRF-1'])->assertRedirect();

    $this->asTenant('acme', function () use ($order) {
        expect(Expense::query()->where('project_id', $this->project->id)->count())->toBe(1)
            ->and(Expense::query()->findOrFail($order->expense_id)->status)->toBe(ExpenseStatus::Paid);
    });
    $this->actingAs($this->buyer);
    $this->get(procurementUrl($order->purchase_request_id))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal/procurement/show')
        ->where('orders.0.spending', 'paid')
        ->where('orders.0.fullyReceived', true)
        ->where('can.receive', false));
});

it('holds the commitment once goods have arrived, and releases it before', function () {
    $order = anOrder();
    financeApproves($order);
    $cancel = $this->organizationUrl('acme', "projects/{$this->project->id}/expenses/{$order->expense_id}/cancel");

    $this->actingAs($this->buyer);
    $this->post(orderPath($order, 'receipts'), ['received_on' => '2026-11-04', 'quantities' => allLines($order)])->assertSessionHasNoErrors();

    $this->actingAs($this->finance);
    $this->post($cancel, ['reason' => 'Changed our mind'])->assertSessionHasErrors('reason');
    expect($this->asTenant('acme', fn () => Expense::query()->findOrFail($order->expense_id)->status))->toBe(ExpenseStatus::Approved);
});

it('releases an order\'s commitment when nothing has arrived', function () {
    $order = anOrder();
    financeApproves($order);

    $this->actingAs($this->finance);
    $this->post($this->organizationUrl('acme', "projects/{$this->project->id}/expenses/{$order->expense_id}/cancel"), ['reason' => 'Supplier closed'])->assertSessionHasNoErrors();
    expect($this->asTenant('acme', fn () => Expense::query()->findOrFail($order->expense_id)->status))->toBe(ExpenseStatus::Cancelled);
});

it('estimates from the lines, and keeps a draft until submitted', function () {
    $purchase = aPurchase();

    // 4 x 250.50 + 10.5 x 10.
    expect($purchase->estimate_minor)->toBe(110700)
        ->and($purchase->status->value)->toBe('draft')
        ->and($purchase->number)->toBe('PR-000001');

    $this->post(procurementUrl(), ['project_id' => $this->project->id, 'title' => 'x', 'justification' => 'y', 'category' => 'labour', 'lines' => [['description' => 'a', 'quantity' => '1', 'unit_price' => '1']]])->assertSessionHasErrors('category');
    $this->post(procurementUrl(), ['project_id' => $this->project->id, 'title' => 'x', 'justification' => 'y', 'category' => 'materials', 'lines' => []])->assertSessionHasErrors('lines');
    $this->post(procurementUrl(), ['project_id' => $this->project->id, 'title' => 'x', 'justification' => 'y', 'category' => 'materials', 'lines' => [['description' => 'a', 'quantity' => '0', 'unit_price' => '1']]])->assertSessionHasErrors('lines.0.quantity');

    $this->actingAs($this->approver);
    $this->post(procurementUrl("{$purchase->id}/approve"))->assertSessionHasErrors('status');
});

it('tells approvers and the requester, and keeps the requester from deciding their own', function () {
    $owner = $this->member('acme', [], owner: true);
    $purchase = aPurchase();
    $this->post(procurementUrl("{$purchase->id}/submit"))->assertSessionHasNoErrors();

    expect($this->asTenant('acme', fn () => UserNotification::query()->where('user_id', $this->approver->id)->value('url')))->toContain("/procurement/{$purchase->id}");

    // The owner holds everything, but not when it is their own request.
    $this->actingAs($owner);
    $mine = aPurchaseBy($owner);
    $this->post(procurementUrl("{$mine->id}/submit"))->assertSessionHasNoErrors();
    $this->post(procurementUrl("{$mine->id}/approve"))->assertSessionHasErrors('status');

    $this->actingAs($this->approver);
    $this->post(procurementUrl("{$purchase->id}/reject"), ['note' => ''])->assertSessionHasErrors('note');
    $this->post(procurementUrl("{$purchase->id}/reject"), ['note' => 'Not in the plan'])->assertSessionHasNoErrors();
    expect($purchase->fresh()?->status->value)->toBe('rejected')
        ->and($this->asTenant('acme', fn () => UserNotification::query()->where('user_id', $this->requester->id)->value('excerpt')))->toBe('Not in the plan');

    $this->actingAs($this->buyer);
    $this->post(procurementUrl("{$purchase->id}/quotes"), ['supplier_name' => 'Late Ltd', 'amount' => '10'])->assertSessionHasErrors('status');
});

function aPurchaseBy(User $user): PurchaseRequest
{
    test()->actingAs($user);
    test()->post(procurementUrl(), ['project_id' => test()->project->id, 'title' => 'Own', 'justification' => 'Mine', 'category' => 'materials', 'lines' => [['description' => 'a', 'quantity' => '1', 'unit_price' => '1']]])->assertSessionHasNoErrors();

    return test()->asTenant('acme', fn () => PurchaseRequest::query()->where('title', 'Own')->firstOrFail());
}

it('asks for a reason when there are few quotes or the lowest is passed over', function () {
    $purchase = anApprovedPurchase();
    $low = aQuote($purchase, 'Low Ltd', '1000');
    $high = aQuote($purchase, 'High Ltd', '2000');
    $choose = fn (PurchaseQuote $quote) => procurementUrl("{$purchase->id}/quotes/{$quote->id}/choose");

    $this->post($choose($low))->assertSessionHasErrors('reason');
    $this->post(procurementUrl("{$purchase->id}/orders"))->assertSessionHasErrors('order');
    aQuote($purchase, 'Mid Ltd', '1500');
    $this->post($choose($high))->assertSessionHasErrors('reason');
    $this->post($choose($high), ['reason' => 'Only one with spare parts in Juba'])->assertSessionHasNoErrors();

    expect($purchase->fresh()?->selected_quote_id)->toBe($high->id)
        ->and($this->asTenant('acme', fn () => AuditEvent::query()->where('action', 'purchase.quote_chosen')->count()))->toBe(1);

    $this->post(procurementUrl("{$purchase->id}/quotes"), ['supplier_name' => 'Low Ltd', 'amount' => '900'])->assertSessionHasErrors('supplier_id');

    // Removing the chosen quote clears the choice, and its supplier stays.
    $this->delete(procurementUrl("{$purchase->id}/quotes/{$high->id}"))->assertSessionHasNoErrors();
    expect($purchase->fresh()?->selected_quote_id)->toBeNull()
        ->and($this->asTenant('acme', fn () => Supplier::query()->where('name', 'High Ltd')->exists()))->toBeTrue();
});

it('refuses an expired quote', function () {
    $purchase = anApprovedPurchase();
    $old = aQuote($purchase, 'Old Ltd', '1000', ['valid_until' => '2026-10-01']);
    aQuote($purchase, 'B', '2000');
    aQuote($purchase, 'C', '3000');

    $this->post(procurementUrl("{$purchase->id}/quotes/{$old->id}/choose"))->assertSessionHasErrors('quote');
});

it('keeps quote documents private, and reachable only through their own request', function () {
    $purchase = anApprovedPurchase();
    $this->actingAs($this->buyer);
    $this->post(procurementUrl("{$purchase->id}/quotes"), ['supplier_name' => 'Doc Ltd', 'amount' => '10', 'document' => UploadedFile::fake()->create('virus.exe', 5)])->assertSessionHasErrors('document');
    $quote = aQuote($purchase, 'Doc Ltd', '10', ['document' => UploadedFile::fake()->create('quote.pdf', 5, 'application/pdf')]);

    Storage::disk('local')->assertExists((string) $quote->path);
    $this->get(procurementUrl("{$purchase->id}/quotes/{$quote->id}/document"))->assertOk();

    $other = anApprovedPurchase();
    $this->actingAs($this->buyer);
    $this->get(procurementUrl("{$other->id}/quotes/{$quote->id}/document"))->assertNotFound();
    $this->post(procurementUrl("{$other->id}/quotes/{$quote->id}/choose"), ['reason' => 'x'])->assertNotFound();

    $this->delete(procurementUrl("{$purchase->id}/quotes/{$quote->id}"))->assertSessionHasNoErrors();
    Storage::disk('local')->assertMissing((string) $quote->path);
});

it('commits through finance, so the budget limit still decides', function () {
    $order = anOrder('4900');
    $second = anOrder('200');

    // Both only asked; each is checked when finance approves.
    financeApproves($order);
    $this->post($this->organizationUrl('acme', "projects/{$this->project->id}/expenses/{$second->expense_id}/approve"))->assertSessionHasErrors('expense');
    expect($second->expense->fresh()?->status)->toBe(ExpenseStatus::Requested);

    // Whoever raised the order can't approve it, even holding the permission.
    $both = $this->member('acme', [...BUYER, 'projects.finance.view', 'finance.expenses.approve'], user: $this->buyer);
    $this->actingAs($both);
    $this->post($this->organizationUrl('acme', "projects/{$this->project->id}/expenses/{$second->expense_id}/approve"))->assertSessionHasErrors();
});

it('charges the grant through the order, and refuses a grant not funding the project', function () {
    $funder = $this->asTenant('acme', fn () => Funder::query()->create(['name' => 'ECHO', 'kind' => 'institutional']));
    $grant = $this->asTenant('acme', fn () => tap(new Grant(['funder_id' => $funder->id, 'reference' => 'E/1', 'title' => 'Water', 'currency' => 'USD', 'amount_minor' => 1000000, 'status' => 'active', 'restricted' => true, 'created_by' => $this->buyer->id]), fn (Grant $g) => $g->save()));

    $this->actingAs($this->requester);
    $this->post(procurementUrl(), ['project_id' => $this->project->id, 'title' => 'x', 'justification' => 'y', 'category' => 'materials', 'grant_id' => $grant->id, 'lines' => [['description' => 'a', 'quantity' => '1', 'unit_price' => '1']]])->assertSessionHasErrors('grant_id');

    $this->asTenant('acme', fn () => $this->project->grantAllocations()->create(['grant_id' => $grant->id, 'amount_minor' => 100000, 'project_amount_minor' => 100000, 'created_by' => $this->buyer->id]));
    $purchase = aPurchase(['grant_id' => $grant->id]);
    $this->post(procurementUrl("{$purchase->id}/submit"));
    $this->actingAs($this->approver);
    $this->post(procurementUrl("{$purchase->id}/approve"));
    $quote = aQuote($purchase, 'A', '500');
    $this->post(procurementUrl("{$purchase->id}/quotes/{$quote->id}/choose"), ['reason' => 'Only supplier'])->assertSessionHasNoErrors();
    $this->post(procurementUrl("{$purchase->id}/orders"))->assertSessionHasNoErrors();

    expect($this->asTenant('acme', fn () => PurchaseOrder::query()->with('expense')->sole()->expense->grant_id))->toBe($grant->id);
});

it('handles part deliveries and refuses more than was ordered', function () {
    $order = anOrder();
    financeApproves($order);
    $this->actingAs($this->buyer);
    [$pump, $pipe] = array_keys(allLines($order));
    $receive = fn (array $quantities) => $this->post(orderPath($order, 'receipts'), ['received_on' => '2026-11-04', 'quantities' => $quantities]);

    $receive([$pump => '0', $pipe => ''])->assertSessionHasErrors('receipt');
    $receive([$pump => '5'])->assertSessionHasErrors('receipt');
    $receive(['01a1ab1a-cde2-70a3-8a24-d5e6cab291a1' => '1'])->assertSessionHasErrors('receipt');
    $receive([$pump => '1.5'])->assertSessionHasNoErrors();
    $receive([$pump => '2.51'])->assertSessionHasErrors('receipt');

    $this->post(orderPath($order, 'receipts'), ['received_on' => '2026-12-25', 'quantities' => [$pump => '1']])->assertSessionHasErrors('received_on');

    $fresh = $this->asTenant('acme', fn () => $order->fresh());
    expect($this->asTenant('acme', fn () => $fresh->isFullyReceived()))->toBeFalse();
    $receive([$pump => '2.5', $pipe => '10.5'])->assertSessionHasNoErrors();
    expect($this->asTenant('acme', fn () => $fresh->isFullyReceived()))->toBeTrue();
});

it('does not pay an order before the goods and the invoice', function () {
    $order = anOrder();
    financeApproves($order);
    $pay = fn () => $this->actingAs($this->finance)->post($this->organizationUrl('acme', "projects/{$this->project->id}/expenses/{$order->expense_id}/pay"), ['paid_on' => '2026-11-04', 'reference' => 'TRF-1']);

    $pay()->assertSessionHasErrors('expense');

    $this->actingAs($this->buyer);
    $this->post(orderPath($order, 'receipts'), ['received_on' => '2026-11-04', 'quantities' => allLines($order)])->assertSessionHasNoErrors();
    $pay()->assertSessionHasErrors('expense');

    $this->actingAs($this->buyer);
    $this->post(orderPath($order, 'invoice'), ['invoice_reference' => 'INV-1', 'invoice_on' => '2026-11-04'])->assertSessionHasNoErrors();
    $pay()->assertSessionHasNoErrors();
    expect($order->expense->fresh()?->status)->toBe(ExpenseStatus::Paid);
});

it('lets a request order again when finance turns the order down', function () {
    $order = anOrder();
    $this->actingAs($this->finance);
    $this->post($this->organizationUrl('acme', "projects/{$this->project->id}/expenses/{$order->expense_id}/reject"), ['reason' => 'Wrong supplier'])->assertSessionHasNoErrors();

    $this->actingAs($this->buyer);
    $this->get(procurementUrl($order->purchase_request_id))->assertInertia(fn (Assert $page) => $page
        ->where('orders.0.live', false)
        ->where('can.buy', true)
        ->where('can.receive', false));
    $this->post(orderPath($order, 'receipts'), ['received_on' => '2026-11-04', 'quantities' => allLines($order)])->assertSessionHasErrors('receipt');

    $this->post(procurementUrl("{$order->purchase_request_id}/orders"))->assertSessionHasNoErrors();
    expect($this->asTenant('acme', fn () => PurchaseOrder::query()->where('purchase_request_id', $order->purchase_request_id)->count()))->toBe(2);

    // While an order stands, a second one is refused.
    $this->post(procurementUrl("{$order->purchase_request_id}/orders"))->assertSessionHasErrors('status');
});

it('holds each step behind its permission', function () {
    $purchase = aPurchase();

    $this->actingAsMember('acme', ['projects.view']);
    $this->get(procurementUrl())->assertForbidden();
    $this->get(procurementUrl($purchase->id))->assertForbidden();
    $this->get(procurementUrl('create'))->assertForbidden();

    $this->actingAs($this->requester);
    $this->post(procurementUrl("{$purchase->id}/approve"))->assertForbidden();
    $this->post(procurementUrl("{$purchase->id}/quotes"), ['supplier_name' => 'x', 'amount' => '1'])->assertForbidden();
    $this->post(procurementUrl("{$purchase->id}/orders"))->assertForbidden();

    // Someone else's draft isn't theirs to submit or cancel.
    $this->actingAsMember('acme', ['procurement.view', 'procurement.request']);
    $this->post(procurementUrl("{$purchase->id}/submit"))->assertForbidden();
    $this->post(procurementUrl("{$purchase->id}/cancel"))->assertForbidden();
});

it('keeps organizations apart', function () {
    $purchase = aPurchase();
    $outsider = $this->member('globex', BUYER);
    $this->actingAs($outsider);

    $this->get(procurementUrl($purchase->id, 'globex'))->assertNotFound();
    $this->post(procurementUrl("{$purchase->id}/submit", 'globex'))->assertNotFound();
    $this->get(procurementUrl('', 'acme'))->assertForbidden();
});

it('lists requests, filtered by status', function () {
    aPurchase();
    $approved = anApprovedPurchase();

    $this->get(procurementUrl())->assertOk()->assertInertia(fn (Assert $page) => $page->component('portal/procurement/index')->has('requests.data', 2));
    $this->get(procurementUrl().'?status=approved')->assertInertia(fn (Assert $page) => $page->has('requests.data', 1)->where('requests.data.0.id', $approved->id));
    $this->actingAs($this->requester);
    $this->get(procurementUrl('create'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('portal/procurement/create')->has('projects', 1));
});

it('shows someone who may only ask their own requests, not everyone else\'s', function () {
    $theirs = aPurchase();
    $asker = $this->member('acme', ['procurement.request']);
    $mine = aPurchaseBy($asker);

    $this->actingAs($asker);
    $this->get(procurementUrl())->assertOk()->assertInertia(fn (Assert $page) => $page->has('requests.data', 1)->where('requests.data.0.id', $mine->id));
    $this->get(procurementUrl($mine->id))->assertOk();
    $this->get(procurementUrl($theirs->id))->assertForbidden();
});

it('warns about the budget only those who may see budgets', function () {
    $purchase = aPurchase();

    $this->actingAs($this->requester);
    $this->get(procurementUrl($purchase->id))->assertInertia(fn (Assert $page) => $page->where('budget', null));

    $this->actingAs($this->finance);
    $this->get(procurementUrl($purchase->id))->assertInertia(fn (Assert $page) => $page->where('budget.leftMinor', 500000));
});
