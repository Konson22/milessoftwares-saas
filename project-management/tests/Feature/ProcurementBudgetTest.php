<?php

use App\Actions\Budgets;
use App\Actions\Procurement;
use App\Enums\CostCategory;
use App\Enums\ExpenseStatus;
use App\Models\AuditEvent;
use App\Models\BudgetLine;
use App\Models\Expense;
use App\Models\FinanceRequest;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\PurchaseQuote;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestLine;
use App\Models\SupplierInvoice;
use App\Support\ProjectFinance;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Procurement against budget lines (ADR-0020): requests name a line of the
| approved budget and are approved only within what is left on it;
| commitments are checked on the line; receipts record rejected goods;
| supplier invoices are matched to the order, never duplicated and never more
| than its commitment; closing an order short releases what was not invoiced;
| Finance is asked to pay only a payable order, and reports tell invoiced
| from committed.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 09:00'));
    Storage::fake('local');
    $this->organization('acme');
    $this->requester = $this->member('acme', ['projects.view', 'procurement.view', 'procurement.request']);
    $this->approver = $this->member('acme', ['procurement.view', 'procurement.approve']);
    $this->buyer = $this->member('acme', ['projects.view', 'procurement.view', 'procurement.request', 'procurement.manage']);
    $this->finance = $this->member('acme', ['projects.view', 'projects.finance.view', 'finance.expenses.approve', 'finance.payments.record', 'procurement.view']);
    $this->project = test()->asTenant('acme', function () {
        $project = new Project(['code' => 'WASH', 'name' => 'Water', 'status' => 'active', 'currency' => 'USD', 'created_by' => $this->buyer->id]);
        $project->save();
        $version = $project->budgetVersions()->create(['number' => 1, 'status' => 'approved', 'created_by' => $this->buyer->id, 'total_minor' => 600000]);
        $version->lines()->create(['project_id' => $project->id, 'category' => 'materials', 'description' => 'Pumps', 'amount_minor' => 300000]);
        $version->lines()->create(['project_id' => $project->id, 'category' => 'materials', 'description' => 'Pipes', 'amount_minor' => 200000]);
        $version->lines()->create(['project_id' => $project->id, 'category' => 'travel', 'description' => 'Field visits', 'amount_minor' => 100000]);

        return $project;
    });
});

function pbUrl(string $path = ''): string
{
    return test()->organizationUrl('acme', 'procurement'.($path === '' ? '' : "/{$path}"));
}

function pbLine(string $description): BudgetLine
{
    return test()->asTenant('acme', fn () => BudgetLine::query()->where('project_id', test()->project->id)->where('description', $description)
        ->where('budget_version_id', Budgets::approved(test()->project)?->id)->firstOrFail());
}

/**
 * A draft request for $price each of 10 units.
 *
 * @param  array<string, mixed>  $extra
 */
function pbDraft(string $price = '100', array $extra = []): PurchaseRequest
{
    test()->actingAs(test()->requester);
    test()->post(pbUrl(), [
        'project_id' => test()->project->id,
        'title' => 'Pumps',
        'justification' => 'Boreholes.',
        'category' => 'materials',
        'lines' => [['description' => 'Pump', 'quantity' => '10', 'unit' => 'each', 'unit_price' => $price]],
        ...$extra,
    ])->assertSessionHasNoErrors()->assertRedirect();

    return test()->asTenant('acme', fn () => PurchaseRequest::query()->latest()->orderByDesc('number')->firstOrFail());
}

function pbApproved(string $price = '100', ?string $line = 'Pumps'): PurchaseRequest
{
    $purchase = pbDraft($price, $line === null ? [] : ['budget_line_id' => pbLine($line)->id]);
    test()->post(pbUrl("{$purchase->id}/submit"))->assertSessionHasNoErrors();
    test()->actingAs(test()->approver);
    test()->post(pbUrl("{$purchase->id}/approve"))->assertSessionHasNoErrors();

    return $purchase->fresh() ?? $purchase;
}

/**
 * An order for $amount from one quote (with a reason), its spending approved by finance.
 */
function pbOrder(string $amount = '1000', string $supplier = 'Pumps Ltd', string $line = 'Pumps', bool $approve = true): PurchaseOrder
{
    $purchase = pbApproved('100', $line);
    test()->actingAs(test()->buyer);
    test()->post(pbUrl("{$purchase->id}/quotes"), ['supplier_name' => $supplier, 'amount' => $amount])->assertSessionHasNoErrors();
    $quote = test()->asTenant('acme', fn () => PurchaseQuote::query()->where('purchase_request_id', $purchase->id)->firstOrFail());
    test()->post(pbUrl("{$purchase->id}/quotes/{$quote->id}/choose"), ['reason' => 'Sole supplier in Juba.'])->assertSessionHasNoErrors();
    test()->post(pbUrl("{$purchase->id}/orders"))->assertSessionHasNoErrors();
    $order = test()->asTenant('acme', fn () => PurchaseOrder::query()->where('purchase_request_id', $purchase->id)->with('expense')->firstOrFail());
    if ($approve) {
        test()->actingAs(test()->finance);
        test()->post(test()->organizationUrl('acme', "projects/{$order->expense->project_id}/expenses/{$order->expense_id}/approve"))->assertSessionHasNoErrors();
    }

    return test()->asTenant('acme', fn () => $order->fresh(['expense']));
}

function pbPath(PurchaseOrder $order, string $path): string
{
    return pbUrl("{$order->purchase_request_id}/orders/{$order->id}/{$path}");
}

function pbRequestLine(PurchaseOrder $order): PurchaseRequestLine
{
    return test()->asTenant('acme', fn () => PurchaseRequestLine::query()->where('purchase_request_id', $order->purchase_request_id)->firstOrFail());
}

function pbExpense(PurchaseOrder $order): Expense
{
    return test()->asTenant('acme', fn () => Expense::query()->findOrFail($order->expense_id));
}

function pbPay(PurchaseOrder $order): TestResponse
{
    test()->actingAs(test()->finance);

    return test()->post(test()->organizationUrl('acme', "projects/{$order->expense->project_id}/expenses/{$order->expense_id}/pay"), ['paid_on' => '2026-11-04', 'reference' => 'TRF-1']);
}

// ---- Budget lines -----------------------------------------------------------

it('keeps a line\'s key across budget versions, and gives new lines their own', function () {
    $this->asTenant('acme', function () {
        $pumps = pbLine('Pumps');
        expect($pumps->line_key)->not->toBeNull();

        $draft = Budgets::revise($this->project, $this->buyer);
        $copy = $draft->lines()->where('description', 'Pumps')->firstOrFail();
        $added = Budgets::addLine($this->project, CostCategory::Materials, 'Tanks', 50000, $this->buyer);

        expect($copy->id)->not->toBe($pumps->id)
            ->and($copy->line_key)->toBe($pumps->line_key)
            ->and($added->line_key)->not->toBe($pumps->line_key);
    });
});

it('charges a request to the one line of its category, and asks which when there are several', function () {
    // Travel has one line: taken without asking.
    $this->actingAs($this->requester);
    $this->post(pbUrl(), [
        'project_id' => $this->project->id, 'title' => 'Trip', 'justification' => 'Visits.', 'category' => 'travel',
        'lines' => [['description' => 'Fuel', 'quantity' => '1', 'unit_price' => '50']],
    ])->assertSessionHasNoErrors();
    $trip = $this->asTenant('acme', fn () => PurchaseRequest::query()->where('title', 'Trip')->firstOrFail());
    expect($trip->budget_line_key)->toBe(pbLine('Field visits')->line_key);

    // Materials has two: the draft waits, and submitting asks which.
    $pumps = pbDraft();
    expect($pumps->budget_line_key)->toBeNull();
    $this->post(pbUrl("{$pumps->id}/submit"))->assertSessionHasErrors('budget_line_id');
    $this->post(pbUrl("{$pumps->id}/submit"), ['budget_line_id' => pbLine('Pipes')->id])->assertSessionHasNoErrors();
    expect($pumps->fresh()->budget_line_key)->toBe(pbLine('Pipes')->line_key);
});

it('refuses a line of another category, of another version, or without an approved budget', function () {
    $this->actingAs($this->requester);
    $base = ['project_id' => $this->project->id, 'title' => 'X', 'justification' => 'Y', 'category' => 'materials',
        'lines' => [['description' => 'Pump', 'quantity' => '1', 'unit_price' => '10']]];

    $this->post(pbUrl(), [...$base, 'budget_line_id' => pbLine('Field visits')->id])->assertSessionHasErrors('budget_line_id');
    $draftLine = $this->asTenant('acme', fn () => Budgets::revise($this->project, $this->buyer)->lines()->firstOrFail());
    $this->post(pbUrl(), [...$base, 'budget_line_id' => $draftLine->id])->assertSessionHasErrors('budget_line_id');

    $bare = $this->asTenant('acme', function () {
        $project = new Project(['code' => 'NEW', 'name' => 'No budget', 'status' => 'active', 'currency' => 'USD', 'created_by' => $this->buyer->id]);
        $project->save();

        return $project;
    });
    $this->post(pbUrl(), [...$base, 'project_id' => $bare->id])->assertSessionHasNoErrors();
    $draft = $this->asTenant('acme', fn () => PurchaseRequest::query()->where('project_id', $bare->id)->firstOrFail());
    $this->post(pbUrl("{$draft->id}/submit"))->assertSessionHasErrors('budget_line_id');
    expect($draft->fresh()->status->value)->toBe('draft');
});

it('approves a request only within what is left on its line and in its category', function () {
    // 10 × 350 = 3,500 against Pumps (3,000): refused, though the category has 5,000.
    $over = pbDraft('350', ['budget_line_id' => pbLine('Pumps')->id]);
    $this->post(pbUrl("{$over->id}/submit"))->assertSessionHasNoErrors();
    $this->actingAs($this->approver);
    $this->post(pbUrl("{$over->id}/approve"))->assertSessionHasErrors('status');
    expect($over->fresh()->status->value)->toBe('submitted');

    // Within the line: approved, with the budget facts on record.
    $fits = pbApproved('250', 'Pumps');
    expect($fits->status->value)->toBe('approved');
    $facts = $this->asTenant('acme', fn () => AuditEvent::query()->where('action', 'purchase.approved')->latest('created_at')->firstOrFail()->properties);
    expect($facts['budget_line_key'])->toBe(pbLine('Pumps')->line_key)
        ->and($facts['line_left_minor'])->toBe(300000)
        ->and($facts['category_left_minor'])->toBe(500000);
});

it('checks the line again when the order commits the money, so two orders cannot both take it', function () {
    // Two orders of 2,000 against Pumps (3,000): each request fits alone.
    $first = pbOrder('2000', 'Pumps Ltd', 'Pumps', approve: false);
    $second = pbOrder('2000', 'Water Co', 'Pumps', approve: false);
    expect($first->expense->budget_line_key)->toBe(pbLine('Pumps')->line_key);

    $this->actingAs($this->finance);
    $approve = fn (PurchaseOrder $order) => $this->post($this->organizationUrl('acme', "projects/{$this->project->id}/expenses/{$order->expense_id}/approve"));
    $approve($first)->assertSessionHasNoErrors();
    // The category still has 3,000 left; the line only 1,000.
    $approve($second)->assertSessionHasErrors('expense');

    expect(pbExpense($first)->status)->toBe(ExpenseStatus::Approved)
        ->and(pbExpense($second)->status)->toBe(ExpenseStatus::Requested)
        ->and($this->asTenant('acme', fn () => Budgets::lineAvailable($this->project, pbLine('Pumps')->line_key)))->toBe(100000);
});

it('will not approve a budget revision that takes a line below what is committed on it', function () {
    pbOrder('2000');
    $this->asTenant('acme', function () {
        $draft = Budgets::revise($this->project, $this->buyer);
        $pumps = $draft->lines()->where('description', 'Pumps')->firstOrFail();
        $pumps->forceFill(['amount_minor' => 150000])->save();
        // The category still covers it (Pumps 1,500 + Pipes 2,000): only the line check refuses.
        Budgets::submit($this->project, $draft, null, $this->buyer);

        expect(fn () => Budgets::approve($this->project, $draft->fresh(), $this->finance))->toThrow(ValidationException::class, 'committed or spent on this line');
    });
});

// ---- Receipts ----------------------------------------------------------------

it('records rejected goods, which do not count as received, with private evidence', function () {
    $order = pbOrder('1000');
    $line = pbRequestLine($order);
    $this->actingAs($this->buyer);
    $this->post(pbPath($order, 'receipts'), [
        'received_on' => '2026-11-04',
        'quantities' => [$line->id => '6'],
        'rejected' => [$line->id => '2'],
        'evidence' => UploadedFile::fake()->create('delivery-note.pdf', 20, 'application/pdf'),
    ])->assertSessionHasNoErrors();

    $order = $this->asTenant('acme', fn () => $order->fresh());
    expect($order->receivedByLine()[$line->id])->toBe('6.00')
        ->and($order->isFullyReceived())->toBeFalse();
    // 4 still to come: the rejected 2 are replaced, not counted.
    $this->post(pbPath($order, 'receipts'), ['received_on' => '2026-11-04', 'quantities' => [$line->id => '5']])->assertSessionHasErrors('receipt');

    $receipt = $this->asTenant('acme', fn () => $order->receipts()->firstOrFail());
    $this->get(pbPath($order, "receipts/{$receipt->id}/evidence"))->assertOk();
    $this->actingAs($this->member('acme', ['projects.view']));
    $this->get(pbPath($order, "receipts/{$receipt->id}/evidence"))->assertForbidden();

    $this->actingAs($this->buyer);
    $this->get(pbUrl($order->purchase_request_id))->assertInertia(fn (Assert $page) => $page
        ->where('lines.0.received', '6.00')
        ->where('lines.0.rejected', '2.00')
        ->where('orders.0.receipts.0.rejected', true)
        ->where('orders.0.receipts.0.evidence', 'delivery-note.pdf'));
});

// ---- Supplier invoices -----------------------------------------------------------

it('matches invoices to the order: its currency, never more than the commitment, and payable only when they come to it', function () {
    $order = pbOrder('1000');
    $line = pbRequestLine($order);
    $this->actingAs($this->buyer);
    $this->post(pbPath($order, 'receipts'), ['received_on' => '2026-11-04', 'quantities' => [$line->id => '10']])->assertSessionHasNoErrors();

    $invoice = fn (array $over) => $this->post(pbPath($order, 'invoice'), ['number' => 'INV-1', 'amount' => '600', 'currency' => 'USD', 'invoice_on' => '2026-11-04', ...$over]);
    $invoice(['currency' => 'SSP'])->assertSessionHasErrors('currency');
    $invoice(['amount' => '1000.01'])->assertSessionHasErrors('amount');
    $invoice([])->assertSessionHasNoErrors();

    // 600 of 1,000 invoiced: not payable yet.
    pbPay($order)->assertSessionHasErrors('expense');
    $this->actingAs($this->buyer);
    $invoice(['number' => 'INV-2', 'amount' => '500'])->assertSessionHasErrors('amount');
    $invoice(['number' => 'INV-2', 'amount' => '400'])->assertSessionHasNoErrors();

    $order = $this->asTenant('acme', fn () => $order->fresh(['expense']));
    expect($order->invoicedMinor())->toBe(100000)
        ->and($order->isPayable())->toBeTrue()
        ->and($order->invoice_reference)->toBe('INV-1');
    pbPay($order)->assertSessionHasNoErrors();
    expect(pbExpense($order)->status)->toBe(ExpenseStatus::Paid);
});

it('refuses the same supplier invoice twice, on any order, also as an older reference', function () {
    $first = pbOrder('1000', 'Pumps Ltd');
    $second = pbOrder('500', 'Pumps Ltd', 'Pipes');
    $other = pbOrder('500', 'Water Co', 'Pipes');
    $this->actingAs($this->buyer);
    $invoice = fn (PurchaseOrder $order, string $number, string $amount = '100') => $this->post(pbPath($order, 'invoice'), ['number' => $number, 'amount' => $amount, 'invoice_on' => '2026-11-04']);

    $invoice($first, 'INV 001')->assertSessionHasNoErrors();
    $invoice($first, 'inv-001')->assertSessionHasErrors('number');
    $invoice($second, 'INV001')->assertSessionHasErrors('number');
    // Another supplier may use the same number.
    $invoice($other, 'INV-001')->assertSessionHasNoErrors();

    // An order invoiced before invoices were kept: its reference counts too.
    $this->asTenant('acme', fn () => PurchaseOrder::query()->whereKey($second->id)->update(['invoice_reference' => 'OLD-9', 'invoice_on' => '2026-11-01']));
    $invoice($first, 'old 9')->assertSessionHasErrors('number');
});

it('voids a wrong invoice with a reason, keeps it, and frees its number, until Finance has it', function () {
    config(['finance_integration.enabled' => true]);
    $this->organization('acme')->forceFill(['finance_mode' => 'integrated'])->save();
    $order = pbOrder('1000');
    $this->actingAs($this->buyer);
    $this->post(pbPath($order, 'invoice'), ['number' => 'INV-5', 'amount' => '900', 'invoice_on' => '2026-11-04'])->assertSessionHasNoErrors();
    $wrong = $this->asTenant('acme', fn () => SupplierInvoice::query()->where('number', 'INV-5')->firstOrFail());

    $this->post(pbPath($order, "invoices/{$wrong->id}/void"))->assertSessionHasErrors('reason');
    $this->post(pbPath($order, "invoices/{$wrong->id}/void"), ['reason' => 'Typed 900, it says 1,000.'])->assertSessionHasNoErrors();
    expect($wrong->fresh()->status)->toBe('voided')
        ->and($this->asTenant('acme', fn () => SupplierInvoice::query()->count()))->toBe(1);

    $this->post(pbPath($order, 'invoice'), ['number' => 'INV-5', 'amount' => '1000', 'invoice_on' => '2026-11-04'])->assertSessionHasNoErrors();
    $this->post(pbPath($order, 'receipts'), ['received_on' => '2026-11-04', 'quantities' => [pbRequestLine($order)->id => '10']])->assertSessionHasNoErrors();
    // Payable, so Finance has it: the invoice stays.
    $live = $this->asTenant('acme', fn () => SupplierInvoice::query()->live()->firstOrFail());
    $this->post(pbPath($order, "invoices/{$live->id}/void"), ['reason' => 'Late change'])->assertSessionHasErrors('invoice');
    expect($this->asTenant('acme', fn () => FinanceRequest::query()->where('expense_id', $order->expense_id)->count()))->toBe(1);
});

// ---- Closing short ----------------------------------------------------------------

it('closes an order delivered short on its invoice, and gives the rest back to the line', function () {
    $order = pbOrder('1000');
    $line = pbRequestLine($order);
    $pumps = pbLine('Pumps')->line_key;
    $left = fn () => $this->asTenant('acme', fn () => Budgets::lineAvailable($this->project, $pumps));
    expect($left())->toBe(200000);

    $this->actingAs($this->buyer);
    $close = fn (array $data = ['reason' => 'Supplier has no more stock.']) => $this->post(pbPath($order, 'close'), $data);
    $close()->assertSessionHasErrors('close');      // nothing arrived: cancel instead
    $this->post(pbPath($order, 'receipts'), ['received_on' => '2026-11-04', 'quantities' => [$line->id => '7']])->assertSessionHasNoErrors();
    $close()->assertSessionHasErrors('close');      // no invoice yet
    $this->post(pbPath($order, 'invoice'), ['number' => 'INV-7', 'amount' => '700', 'invoice_on' => '2026-11-04'])->assertSessionHasNoErrors();
    pbPay($order)->assertSessionHasErrors('expense'); // not everything arrived

    $this->actingAs($this->buyer);
    $close([])->assertSessionHasErrors('reason');
    $close()->assertSessionHasNoErrors();

    $order = $this->asTenant('acme', fn () => $order->fresh(['expense']));
    expect($order->amount_minor)->toBe(100000)           // what was ordered stays
        ->and($order->expense->amount_minor)->toBe(70000) // the commitment is what was invoiced
        ->and($order->isPayable())->toBeTrue()
        ->and($left())->toBe(230000);
    $audit = $this->asTenant('acme', fn () => AuditEvent::query()->where('action', 'purchase.order_closed')->firstOrFail()->properties);
    expect($audit['commitment_from_minor'])->toBe(100000)->and($audit['released_minor'])->toBe(30000);

    // Closed: nothing more arrives or is invoiced against it.
    $this->post(pbPath($order, 'receipts'), ['received_on' => '2026-11-04', 'quantities' => [$line->id => '1']])->assertSessionHasErrors('receipt');
    $this->post(pbPath($order, 'invoice'), ['number' => 'INV-8', 'amount' => '10', 'invoice_on' => '2026-11-04'])->assertSessionHasErrors('invoice');

    pbPay($order)->assertSessionHasNoErrors();
    expect(pbExpense($order)->status)->toBe(ExpenseStatus::Paid)->and(pbExpense($order)->amount_minor)->toBe(70000);
});

it('sends Finance only the invoiced commitment of an order closed short, once', function () {
    config(['finance_integration.enabled' => true]);
    $this->organization('acme')->forceFill(['finance_mode' => 'integrated'])->save();
    $order = pbOrder('1000');
    $this->actingAs($this->buyer);
    $this->post(pbPath($order, 'receipts'), ['received_on' => '2026-11-04', 'quantities' => [pbRequestLine($order)->id => '4']])->assertSessionHasNoErrors();
    $this->post(pbPath($order, 'invoice'), ['number' => 'INV-4', 'amount' => '400', 'invoice_on' => '2026-11-04'])->assertSessionHasNoErrors();
    expect($this->financeEvents('acme', 'finance.spending.submitted'))->toBe([]);

    $this->post(pbPath($order, 'close'), ['reason' => 'Rest cancelled.'])->assertSessionHasNoErrors();
    $events = $this->financeEvents('acme', 'finance.spending.submitted');
    expect($events)->toHaveCount(1)
        ->and($events[0]['payload']['amount_minor'])->toBe(40000)
        ->and($this->asTenant('acme', fn () => FinanceRequest::query()->where('expense_id', $order->expense_id)->value('amount_minor')))->toBe(40000);

    // Projects never pays it itself.
    pbPay($order)->assertSessionHasErrors('expense');
});

it('keeps an order invoiced before invoices were kept payable as before', function () {
    $order = pbOrder('1000');
    $this->actingAs($this->buyer);
    $this->post(pbPath($order, 'receipts'), ['received_on' => '2026-11-04', 'quantities' => [pbRequestLine($order)->id => '10']])->assertSessionHasNoErrors();
    $this->asTenant('acme', fn () => PurchaseOrder::query()->whereKey($order->id)->update(['invoice_reference' => 'LEGACY-1', 'invoice_on' => '2026-11-03']));

    $order = $this->asTenant('acme', fn () => $order->fresh(['expense']));
    expect($order->hasLegacyInvoice())->toBeTrue()->and($order->isPayable())->toBeTrue()
        ->and($this->asTenant('acme', fn () => ProjectFinance::for($this->project)['invoicedMinor']))->toBe(100000);
    pbPay($order)->assertSessionHasNoErrors();
});

// ---- Reports ---------------------------------------------------------------

it('tells invoiced from committed and paid, and keeps it inside the organization', function () {
    $invoiced = pbOrder('1000');
    pbOrder('500', 'Water Co', 'Pipes');
    $this->actingAs($this->buyer);
    $this->post(pbPath($invoiced, 'invoice'), ['number' => 'INV-1', 'amount' => '250', 'invoice_on' => '2026-11-04'])->assertSessionHasNoErrors();

    $figures = $this->asTenant('acme', fn () => ProjectFinance::for($this->project));
    expect($figures['committedMinor'])->toBe(150000)
        ->and($figures['invoicedMinor'])->toBe(25000)
        ->and($figures['paidMinor'])->toBe(0)
        ->and($figures['availableMinor'])->toBe(600000 - 150000)
        ->and(ProjectFinance::shareable($figures))->not->toHaveKey('invoicedMinor');
});

// ---- Access ------------------------------------------------------------------

it('holds invoices and closing behind procurement.manage, and team-only projects to their team', function () {
    $order = pbOrder('1000');
    $line = pbRequestLine($order);
    $this->actingAs($this->buyer);
    $this->post(pbPath($order, 'receipts'), ['received_on' => '2026-11-04', 'quantities' => [$line->id => '5']])->assertSessionHasNoErrors();
    $this->post(pbPath($order, 'invoice'), ['number' => 'INV-1', 'amount' => '500', 'invoice_on' => '2026-11-04', 'document' => UploadedFile::fake()->create('inv.pdf', 10, 'application/pdf')])->assertSessionHasNoErrors();
    $invoice = $this->asTenant('acme', fn () => SupplierInvoice::query()->firstOrFail());

    $this->actingAs($this->requester);
    $this->post(pbPath($order, 'invoice'), ['number' => 'INV-2', 'amount' => '1', 'invoice_on' => '2026-11-04'])->assertForbidden();
    $this->post(pbPath($order, 'close'), ['reason' => 'x'])->assertForbidden();
    $this->post(pbPath($order, "invoices/{$invoice->id}/void"), ['reason' => 'x'])->assertForbidden();
    $this->get(pbPath($order, "invoices/{$invoice->id}/document"))->assertOk();

    // Team-only: a buyer outside the team does not see the order at all.
    $this->asTenant('acme', fn () => Project::query()->whereKey($this->project->id)->update(['visibility' => 'team']));
    $outsider = $this->member('acme', ['projects.view', 'procurement.view', 'procurement.request', 'procurement.manage']);
    $this->actingAs($outsider);
    $this->get(pbPath($order, "invoices/{$invoice->id}/document"))->assertNotFound();
    $this->post(pbPath($order, 'close'), ['reason' => 'x'])->assertNotFound();
    $this->post(pbPath($order, 'invoice'), ['number' => 'INV-3', 'amount' => '1', 'invoice_on' => '2026-11-04'])->assertNotFound();
});

it('keeps another organization out of orders, invoices and receipts', function () {
    $order = pbOrder('1000');
    $this->actingAs($this->buyer);
    $this->post(pbPath($order, 'invoice'), ['number' => 'INV-1', 'amount' => '100', 'invoice_on' => '2026-11-04'])->assertSessionHasNoErrors();
    $invoice = $this->asTenant('acme', fn () => SupplierInvoice::query()->firstOrFail());

    $this->organization('globex');
    $stranger = $this->member('globex', ['projects.view', 'procurement.view', 'procurement.manage']);
    $this->actingAs($stranger);
    $path = fn (string $p) => $this->organizationUrl('globex', "procurement/{$order->purchase_request_id}/orders/{$order->id}/{$p}");
    $this->get($path("invoices/{$invoice->id}/document"))->assertNotFound();
    $this->post($path('close'), ['reason' => 'x'])->assertNotFound();
    $this->post($path('invoice'), ['number' => 'X', 'amount' => '1', 'invoice_on' => '2026-11-04'])->assertNotFound();
});

it('refuses to close an order nothing arrived on, and never edits the ordered amount', function () {
    $order = pbOrder('1000');
    $this->asTenant('acme', function () use ($order) {
        expect(fn () => Procurement::closeOrder($order, 'Short', $this->buyer))->toThrow(ValidationException::class, 'Nothing has arrived');
    });
    expect($this->asTenant('acme', fn () => $order->fresh()->amount_minor))->toBe(100000);
});

it('shows the order\'s three-way picture and what can be done next', function () {
    $order = pbOrder('1000');
    $this->actingAs($this->buyer);
    $this->post(pbPath($order, 'receipts'), ['received_on' => '2026-11-04', 'quantities' => [pbRequestLine($order)->id => '3']])->assertSessionHasNoErrors();
    $this->post(pbPath($order, 'invoice'), ['number' => 'INV-3', 'amount' => '300', 'invoice_on' => '2026-11-04', 'due_on' => '2026-12-04'])->assertSessionHasNoErrors();

    $this->get(pbUrl($order->purchase_request_id))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal/procurement/show')
        ->where('budgetLine.label', 'Materials — Pumps')
        ->where('orders.0.amountMinor', 100000)
        ->where('orders.0.commitmentMinor', 100000)
        ->where('orders.0.invoicedMinor', 30000)
        ->where('orders.0.payable', false)
        ->where('orders.0.invoices.0.number', 'INV-3')
        ->where('orders.0.invoices.0.dueOn', '2026-12-04')
        ->where('orders.0.can.invoice', true)
        ->where('orders.0.can.close', true)
        ->where('orders.0.finance', null));

    $this->actingAs($this->member('acme', ['procurement.view']));
    $this->get(pbUrl($order->purchase_request_id))->assertInertia(fn (Assert $page) => $page
        ->where('orders.0.can.invoice', false)
        ->where('orders.0.can.close', false)
        ->where('budget', null));
});

it('lets the request form offer the approved lines, with what is left only to budget viewers', function () {
    $this->actingAs($this->requester);
    $this->get(pbUrl('create'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('projects.0.budgetLines', 3)
        ->where('projects.0.budgetLines.0.leftMinor', null));

    $viewer = $this->member('acme', ['projects.view', 'projects.finance.view', 'procurement.request']);
    $this->actingAs($viewer);
    pbOrder('1000');
    $this->actingAs($viewer);
    $this->get(pbUrl('create'))->assertInertia(fn (Assert $page) => $page
        ->where('projects.0.budgetLines', fn ($lines) => collect($lines)->firstWhere('label', 'Materials — Pumps')['leftMinor'] === 200000));
});

it('shows each approved line with what is committed and paid on it, and Finance what is invoiced', function () {
    $paid = pbOrder('1000');
    pbOrder('500', 'Water Co', 'Pumps');
    $this->actingAs($this->buyer);
    $this->post(pbPath($paid, 'receipts'), ['received_on' => '2026-11-04', 'quantities' => [pbRequestLine($paid)->id => '10']])->assertSessionHasNoErrors();
    $this->post(pbPath($paid, 'invoice'), ['number' => 'INV-1', 'amount' => '1000', 'invoice_on' => '2026-11-04'])->assertSessionHasNoErrors();
    pbPay($paid)->assertSessionHasNoErrors();

    $viewer = $this->member('acme', ['projects.view', 'projects.finance.view', 'finance.commitments.view']);
    $this->actingAs($viewer);
    $this->get($this->organizationUrl('acme', "projects/{$this->project->id}/budget"))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal/projects/budget')
        ->where('versions.0.lines', fn ($lines) => collect($lines)->firstWhere('description', 'Pumps')['use'] === ['committedMinor' => 50000, 'paidMinor' => 100000]
            && collect($lines)->firstWhere('description', 'Pipes')['use'] === ['committedMinor' => 0, 'paidMinor' => 0]));

    $this->get($this->organizationUrl('acme', 'commitments'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('commitments', 1)
        ->where('commitments.0.amountMinor', 50000)
        ->where('commitments.0.invoicedMinor', 0));
});
