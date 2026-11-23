<?php

use App\Procurement\Models\AuditEvent;
use App\Procurement\Models\PurchaseOrder;
use App\Procurement\Models\Requisition;
use App\Procurement\Models\SupplierInvoice;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Scenario A (ADR-0023): an organization subscribed to Procurement only
 * runs the whole cycle, requisition to closure, with no other application.
 */

beforeEach(function () {
    $this->team = team();

    // A second currency, rated by the workspace (1 USD = 4.50 SSP).
    $this->actingAsMember('acme', [], owner: true);
    $this->post(p('settings/currencies'), ['code' => 'USD', 'rate' => '4.50'])->assertSessionHasNoErrors();
});

it('runs requisition → approval → RFQ → evaluation → award → order → receipt → invoice → closure', function () {
    ['requester' => $requester, 'approver' => $approver, 'officer' => $officer, 'manager' => $manager, 'evaluator' => $evaluator, 'receiver' => $receiver] = $this->team;

    // Suppliers
    as_($officer, 'procurement_officer');
    $alpha = supplier('Alpha Traders');
    $beta = supplier('Beta Computers Ltd');
    $gamma = supplier('Gamma Imports');

    // Requisition, 4 × 1,000 + 400 = 4,400 SSP, submitted by the requester
    as_($requester, 'procurement_requester');
    $requisition = requisition();
    expect($requisition->status)->toBe('pending_approval')
        ->and($requisition->total_minor)->toBe(440000)
        ->and($requisition->number)->toBe('REQ-'.now()->year.'-0001');

    // The requester cannot approve it (no permission), the approver can.
    decide("requisitions/{$requisition->id}/decide")->assertForbidden();
    as_($approver, 'procurement_department_approver');
    decide("requisitions/{$requisition->id}/decide")->assertSessionHasNoErrors();
    expect(inTenant(fn () => $requisition->fresh()->status))->toBe('approved');

    // RFQ: three suppliers, criteria fixed at issue
    as_($officer, 'procurement_officer');
    $event = issuedRfq($requisition, [$alpha, $beta, $gamma]);
    expect($event->status)->toBe('issued');
    $this->put(p("sourcing/{$event->id}/criteria"), ['criteria' => []])->assertSessionHasErrors('event');

    $qa = quote($event, $alpha, [1 => '900', 2 => '300']);                    // 3,900 SSP
    $qb = quote($event, $beta, [1 => '1000', 2 => '350']);                    // 4,350 SSP
    $qg = quote($event, $gamma, [1 => '210', 2 => '50'], currency: 'USD');    // 890 USD = 4,005 SSP
    expect($qg->total_minor)->toBe(89000)
        ->and($qg->rate_minor)->toBe(450)
        ->and($qg->total_base_minor)->toBe(400500);

    $this->post(p("sourcing/{$event->id}/close"))->assertSessionHasNoErrors();
    // Closed quotations stay as they are.
    $this->post(p("sourcing/{$event->id}/quotations"), ['supplier_id' => $beta->id, 'quoted_on' => now()->toDateString(), 'currency' => 'SSP', 'lines' => []])
        ->assertSessionHasErrors();
    $this->post(p("sourcing/{$event->id}/evaluation"))->assertSessionHasNoErrors();

    // The evaluator: Alpha fails the mandatory specification.
    as_($evaluator, 'procurement_evaluator');
    $criteria = inTenant(fn () => $event->criteria()->get()->keyBy('kind'));
    foreach ([[$qa, false, 6], [$qb, true, 9], [$qg, true, 5]] as [$q, $passed, $score]) {
        $this->post(p("sourcing/{$event->id}/quotations/{$q->id}/evaluate"), ['results' => [
            $criteria['pass_fail']->id => ['passed' => $passed],
            $criteria['score']->id => ['score' => $score],
        ]])->assertSessionHasNoErrors();
    }

    // Comparison: Alpha is cheapest but non-compliant; Gamma ranks first in SSP.
    $this->get(p("sourcing/{$event->id}"))->assertInertia(fn (Assert $page) => $page
        ->component('sourcing/show')
        ->where('quotations.0.compliance', 'non_compliant')
        ->where('quotations', fn ($rows) => collect($rows)->firstWhere('supplier', 'Gamma Imports')['rank'] === 1
            && collect($rows)->firstWhere('supplier', 'Beta Computers Ltd')['rank'] === 2
            && collect($rows)->firstWhere('supplier', 'Alpha Traders')['rank'] === null));

    // Non-compliant cannot win; the second-ranked needs a written reason.
    as_($officer, 'procurement_officer');
    $this->post(p("sourcing/{$event->id}/award"), ['quotation_id' => $qa->id, 'justification' => 'Cheapest'])->assertSessionHasErrors('quotation_id');
    $this->post(p("sourcing/{$event->id}/award"), ['quotation_id' => $qb->id, 'justification' => 'Local support'])->assertSessionHasErrors('deviation_reason');
    $this->post(p("sourcing/{$event->id}/award"), [
        'quotation_id' => $qb->id, 'justification' => 'Local support', 'deviation_reason' => 'Gamma imports with 10-week lead time; the need is in 4 weeks.',
    ])->assertSessionHasNoErrors();

    // The officer recommended it, so cannot approve it even with the permission.
    $award = award($event);
    as_($officer, 'procurement_officer', extra: ['procurement.awards.approve']);
    decide("sourcing/{$event->id}/awards/{$award->id}/decide")->assertSessionHasErrors(['decision' => 'You submitted this, so someone else must decide it.']);
    as_($manager, 'procurement_manager');
    decide("sourcing/{$event->id}/awards/{$award->id}/decide")->assertSessionHasNoErrors();
    expect(inTenant(fn () => [$award->fresh()->status, $event->fresh()->status]))->toBe(['approved', 'awarded']);

    // Purchase order from the award, prepared by the officer, approved by the manager
    as_($officer, 'procurement_officer');
    $this->post(p("awards/{$award->id}/order"), ['delivery_date' => now()->addWeeks(3)->toDateString()])->assertSessionHasNoErrors();
    $order = order();
    expect($order->total_minor)->toBe(435000)->and($order->supplier_id)->toBe($beta->id)->and($order->status)->toBe('draft');
    $this->post(p("orders/{$order->id}/submit"))->assertSessionHasNoErrors();
    as_($officer, 'procurement_officer', extra: ['procurement.orders.approve']);
    decide("orders/{$order->id}/decide")->assertSessionHasErrors(['decision' => 'You submitted this, so someone else must decide it.']);
    as_($manager, 'procurement_manager');
    decide("orders/{$order->id}/decide")->assertSessionHasNoErrors();
    as_($officer, 'procurement_officer');
    $this->post(p("orders/{$order->id}/issue"))->assertSessionHasNoErrors();
    $this->get(p("orders/{$order->id}/print"))->assertOk()->assertSee($order->number)->assertSee('Beta Computers Ltd');

    // Partial receipt: 3 laptops arrive, 1 is rejected.
    $lines = inTenant(fn () => $order->lines()->get()->keyBy('line_no'));
    as_($receiver, 'procurement_receiving_officer');
    $this->post(p("orders/{$order->id}/receipts"), [
        'received_on' => now()->toDateString(), 'inspection' => 'partial', 'delivery_note' => 'DN-77',
        'lines' => [['line_id' => $lines[1]->id, 'accepted' => '2', 'rejected' => '1']],
    ])->assertSessionHasErrors('lines.0.rejection_reason');
    $this->post(p("orders/{$order->id}/receipts"), [
        'received_on' => now()->toDateString(), 'inspection' => 'partial', 'delivery_note' => 'DN-77',
        'lines' => [['line_id' => $lines[1]->id, 'accepted' => '2', 'rejected' => '1', 'rejection_reason' => 'Cracked screen']],
    ])->assertSessionHasNoErrors();
    expect(order()->status)->toBe('partially_received');

    // More than ordered is refused (2 remain).
    $this->post(p("orders/{$order->id}/receipts"), [
        'received_on' => now()->toDateString(), 'inspection' => 'passed',
        'lines' => [['line_id' => $lines[1]->id, 'accepted' => '3']],
    ])->assertSessionHasErrors('lines.0.accepted');

    // The full invoice waits for the rest of the goods (three-way match).
    as_($officer, 'procurement_officer');
    $this->post(p("orders/{$order->id}/invoices"), ['number' => 'INV-1001', 'invoice_date' => now()->toDateString(), 'amount' => '4350'])->assertSessionHasNoErrors();
    $invoice = inTenant(fn () => SupplierInvoice::query()->sole());
    expect($invoice->match_status)->toBe('awaiting_receipt');

    // The same supplier invoice cannot be recorded twice; nor beyond the order.
    $this->post(p("orders/{$order->id}/invoices"), ['number' => 'inv 1001', 'invoice_date' => now()->toDateString(), 'amount' => '1'])->assertSessionHasErrors('number');
    $this->post(p("orders/{$order->id}/invoices"), ['number' => 'INV-1002', 'invoice_date' => now()->toDateString(), 'amount' => '1'])->assertSessionHasErrors('amount');

    // The rest arrives, and the service is accepted: the invoice matches.
    as_($receiver, 'procurement_receiving_officer');
    $this->post(p("orders/{$order->id}/receipts"), [
        'received_on' => now()->toDateString(), 'inspection' => 'passed',
        'lines' => [['line_id' => $lines[1]->id, 'accepted' => '2'], ['line_id' => $lines[2]->id, 'accepted' => '1']],
    ])->assertSessionHasNoErrors();
    expect(order()->status)->toBe('received')
        ->and(inTenant(fn () => $invoice->fresh()->match_status))->toBe('matched');

    // Paid outside Tatook: noted by hand, never verified.
    as_($officer, 'procurement_officer');
    $this->post(p("invoices/{$invoice->id}/paid"), ['paid_on' => now()->toDateString(), 'payment_reference' => 'TT 4412'])->assertSessionHasNoErrors();
    expect(inTenant(fn () => $invoice->fresh()->payment_status))->toBe('paid_externally');

    // Closing the order closes the requisition.
    $this->post(p("orders/{$order->id}/close"))->assertSessionHasNoErrors();
    expect(order()->status)->toBe('closed')
        ->and(inTenant(fn () => Requisition::query()->find($requisition->id)->status))->toBe('closed');

    // The trail, and nothing sent to any other application.
    $actions = inTenant(fn () => AuditEvent::query()->pluck('action')->all());
    expect($actions)->toContain('requisition.approved', 'sourcing.issued', 'quotation.evaluated', 'award.approved', 'order.approved', 'order.issued', 'receipt.recorded', 'invoice.recorded', 'invoice.paid_externally', 'order.closed', 'requisition.closed')
        ->and(DB::table('platform_outbox')->count())->toBe(0);

    // The overview keeps committed, invoiced and paid apart.
    as_($manager, 'procurement_manager');
    $this->get(p())->assertInertia(fn (Assert $page) => $page
        ->where('figures.committedMinor', 435000)
        ->where('figures.invoicedMinor', 435000)
        ->where('figures.paidExternallyMinor', 435000)
        ->where('figures.sentToFinanceMinor', 0));
});

it('lets a requester follow their own requisition but nobody else\'s', function () {
    ['requester' => $requester] = $this->team;
    $other = actingAsRole('procurement_requester', name: 'Other');
    $theirs = requisition(['title' => 'Chairs']);

    as_($requester, 'procurement_requester');
    $mine = requisition(['title' => 'Printer toner']);

    $this->get(p("requisitions/{$mine->id}"))->assertOk();
    $this->get(p("requisitions/{$theirs->id}"))->assertNotFound();
    $this->get(p('requisitions'))->assertInertia(fn (Assert $page) => $page->has('requisitions', 1)->where('requisitions.0.title', 'Printer toner'));
    $this->get(p('orders'))->assertForbidden();
    $this->get(p('suppliers'))->assertForbidden();
});

it('returns a requisition for changes and takes it back as the same record', function () {
    ['requester' => $requester, 'approver' => $approver] = $this->team;
    as_($requester, 'procurement_requester');
    $requisition = requisition();

    as_($approver, 'procurement_department_approver');
    decide("requisitions/{$requisition->id}/decide", 'return')->assertSessionHasErrors('note');
    decide("requisitions/{$requisition->id}/decide", 'return', 'Quote the 8 GB model.')->assertSessionHasNoErrors();

    as_($requester, 'procurement_requester');
    $this->put(p("requisitions/{$requisition->id}"), [
        'title' => 'Laptops for the finance team', 'justification' => 'Cheaper model', 'currency' => 'SSP',
        'lines' => [['kind' => 'goods', 'description' => 'Laptop, 8 GB', 'quantity' => '4', 'unit_price' => '800']],
        'submit' => true,
    ])->assertSessionHasNoErrors();

    expect(inTenant(fn () => $requisition->fresh()))
        ->status->toBe('pending_approval')
        ->total_minor->toBe(320000);
});

it('amends an approved requisition only while nothing buys against it', function () {
    ['requester' => $requester, 'approver' => $approver, 'officer' => $officer] = $this->team;
    as_($requester, 'procurement_requester');
    $requisition = requisition();
    as_($approver, 'procurement_department_approver');
    decide("requisitions/{$requisition->id}/decide");

    as_($requester, 'procurement_requester');
    $this->post(p("requisitions/{$requisition->id}/amend"), ['reason' => 'One more laptop'])->assertRedirect();
    expect(inTenant(fn () => $requisition->fresh()))->status->toBe('draft')->revision->toBe(2);

    $this->post(p("requisitions/{$requisition->id}/submit"))->assertSessionHasNoErrors();
    as_($approver, 'procurement_department_approver');
    decide("requisitions/{$requisition->id}/decide");

    as_($officer, 'procurement_officer');
    $vendor = supplier('Delta');
    issuedRfq($requisition, [$vendor]);

    as_($requester, 'procurement_requester');
    $this->post(p("requisitions/{$requisition->id}/amend"), ['reason' => 'Again'])->assertSessionHasErrors('requisition');
    $this->post(p("requisitions/{$requisition->id}/cancel"), ['reason' => 'Not needed'])->assertSessionHasErrors('requisition');
});

it('keeps an approved order from changing in place: amendments snapshot it and need approval again', function () {
    ['requester' => $requester, 'approver' => $approver, 'officer' => $officer, 'manager' => $manager] = $this->team;
    as_($requester, 'procurement_requester');
    $requisition = requisition();
    as_($approver, 'procurement_department_approver');
    decide("requisitions/{$requisition->id}/decide");

    as_($officer, 'procurement_officer');
    $vendor = supplier('Echo Office Supplies');
    $this->post(p('orders'), ['requisition_id' => $requisition->id, 'supplier_id' => $vendor->id])->assertSessionHasNoErrors();
    $order = order();
    expect($order->method)->toBe('direct')->and($order->total_minor)->toBe(440000);
    $this->post(p("orders/{$order->id}/submit"));
    as_($manager, 'procurement_manager');
    decide("orders/{$order->id}/decide");

    as_($officer, 'procurement_officer');
    $this->put(p("orders/{$order->id}"), ['payment_terms' => '30 days'])->assertSessionHasErrors('order');
    $this->post(p("orders/{$order->id}/amend"), ['reason' => 'Supplier price change'])->assertSessionHasNoErrors();
    $line = inTenant(fn () => $order->lines()->get());
    $this->put(p("orders/{$order->id}"), ['payment_terms' => '30 days', 'lines' => $line->map(fn ($l) => [
        'id' => $l->id, 'kind' => $l->kind, 'description' => $l->description, 'quantity' => $l->quantity, 'unit_price' => '1050',
    ])->all()])->assertSessionHasNoErrors();

    $order = order();
    expect($order->status)->toBe('draft')->and($order->revision)->toBe(2)->and($order->total_minor)->toBe(525000)
        ->and(inTenant(fn () => $order->revisions()->sole()->snapshot['total_minor']))->toBe(440000);

    // Cancelled while nothing was received.
    $this->post(p("orders/{$order->id}/cancel"), ['reason' => 'Bought elsewhere'])->assertSessionHasNoErrors();
    expect(order()->status)->toBe('cancelled');
});

it('refuses a direct purchase above its limit', function () {
    ['requester' => $requester, 'approver' => $approver, 'officer' => $officer] = $this->team;
    settings(['method_limits' => ['direct' => 100000]]); // 1,000.00 SSP

    as_($requester, 'procurement_requester');
    $requisition = requisition();
    as_($approver, 'procurement_department_approver');
    decide("requisitions/{$requisition->id}/decide");

    as_($officer, 'procurement_officer');
    $vendor = supplier('Foxtrot');
    $this->post(p('orders'), ['requisition_id' => $requisition->id, 'supplier_id' => $vendor->id])->assertSessionHasErrors('method');
    expect(inTenant(fn () => PurchaseOrder::query()->count()))->toBe(0);
});

it('hides sealed prices until the event closes', function () {
    ['requester' => $requester, 'approver' => $approver, 'officer' => $officer] = $this->team;
    as_($requester, 'procurement_requester');
    $requisition = requisition();
    as_($approver, 'procurement_department_approver');
    decide("requisitions/{$requisition->id}/decide");

    as_($officer, 'procurement_officer');
    $one = supplier('One');
    $event = issuedRfq($requisition, [$one], 'tender');
    expect($event->sealed)->toBeTrue();
    quote($event, $one, [1 => '1000', 2 => '400']);

    $this->get(p("sourcing/{$event->id}"))->assertInertia(fn (Assert $page) => $page->where('quotations.0.total', null)->where('quotations.0.lines.0.unitPrice', null));
    $this->post(p("sourcing/{$event->id}/close"));
    $this->get(p("sourcing/{$event->id}"))->assertInertia(fn (Assert $page) => $page->where('quotations.0.total', '4400.00'));
});

it('asks for a reason when fewer quotations than required were received', function () {
    ['requester' => $requester, 'approver' => $approver, 'officer' => $officer, 'evaluator' => $evaluator] = $this->team;
    as_($requester, 'procurement_requester');
    $requisition = requisition();
    as_($approver, 'procurement_department_approver');
    decide("requisitions/{$requisition->id}/decide");

    as_($officer, 'procurement_officer');
    $only = supplier('Only Bidder');
    $event = issuedRfq($requisition, [$only]);
    $q = quote($event, $only, [1 => '1000', 2 => '400']);
    $this->post(p("sourcing/{$event->id}/close"));
    $this->post(p("sourcing/{$event->id}/evaluation"));

    // Mandatory criterion not evaluated yet.
    $this->post(p("sourcing/{$event->id}/award"), ['quotation_id' => $q->id, 'justification' => 'Only offer'])->assertSessionHasErrors('quotation_id');

    as_($evaluator, 'procurement_evaluator');
    $criterion = inTenant(fn () => $event->criteria()->where('kind', 'pass_fail')->sole());
    $this->post(p("sourcing/{$event->id}/quotations/{$q->id}/evaluate"), ['results' => [$criterion->id => ['passed' => true]]]);

    as_($officer, 'procurement_officer');
    $this->post(p("sourcing/{$event->id}/award"), ['quotation_id' => $q->id, 'justification' => 'Only offer'])
        ->assertSessionHasErrors(['deviation_reason' => 'Explain the choice: only 1 of the 3 quotations expected were received.']);
});
