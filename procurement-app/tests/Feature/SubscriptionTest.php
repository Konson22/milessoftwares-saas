<?php

use App\Procurement\Models\SupplierInvoice;
use Illuminate\Support\Facades\DB;
use Miles\PlatformClient\Http\Middleware\RequirePlatformAccess;

/*
 * Procurement is subscribed to on its own (ADR-0023 §2.2). Optional
 * applications only change what is sent to them.
 */

it('works for a member of an organization entitled to Procurement alone', function () {
    actingAsRole('procurement_officer');

    foreach (['', 'requisitions', 'requisitions/create', 'plans', 'suppliers', 'sourcing', 'orders', 'receiving', 'reports', 'approvals'] as $page) {
        $this->get(p($page))->assertOk();
    }
});

it('refuses a workspace whose Procurement access was disabled or expired', function () {
    $user = $this->platformUser();
    $org = $this->platform()->organization('acme');
    $this->platform()->grant('acme', $user->getAuthIdentifier(), allowed: false);

    $this->actingAs($user)
        ->withSession([RequirePlatformAccess::SESSION_TENANT => $org['id']])
        ->get(p('requisitions'))
        ->assertForbidden();
});

it('sends matched invoices to Finance once when connected, and hands their payment to Finance', function () {
    $team = team();
    ['requester' => $requester, 'approver' => $approver, 'officer' => $officer, 'manager' => $manager, 'receiver' => $receiver] = $team;

    // Settings: the switch needs an expense account.
    $admin = actingAsRole('procurement_administrator');
    $base = ['allow_self_approval' => false, 'require_plan' => false, 'min_quotations' => 3, 'invoice_tolerance_percent' => 0];
    $this->put(p('settings'), [...$base, 'finance_bills_enabled' => true])->assertSessionHasErrors('finance_expense_account');
    $this->put(p('settings'), [...$base, 'finance_bills_enabled' => true, 'finance_expense_account' => '6100'])->assertSessionHasNoErrors();

    as_($requester, 'procurement_requester');
    $requisition = requisition();
    as_($approver, 'procurement_department_approver');
    decide("requisitions/{$requisition->id}/decide");
    as_($officer, 'procurement_officer');
    $vendor = supplier('Hotel Supplies');
    $this->post(p('orders'), ['requisition_id' => $requisition->id, 'supplier_id' => $vendor->id]);
    $order = order();
    $this->post(p("orders/{$order->id}/submit"));
    as_($manager, 'procurement_manager');
    decide("orders/{$order->id}/decide");
    as_($officer, 'procurement_officer');
    $this->post(p("orders/{$order->id}/issue"));

    // Invoiced before anything arrived: held, nothing sent.
    $this->post(p("orders/{$order->id}/invoices"), ['number' => 'H-1', 'invoice_date' => now()->toDateString(), 'amount' => '4400'])->assertSessionHasNoErrors();
    expect(DB::table('platform_outbox')->count())->toBe(0);

    $lines = inTenant(fn () => $order->lines()->get());
    as_($receiver, 'procurement_receiving_officer');
    $this->post(p("orders/{$order->id}/receipts"), [
        'received_on' => now()->toDateString(), 'inspection' => 'passed',
        'lines' => $lines->map(fn ($l) => ['line_id' => $l->id, 'accepted' => $l->quantity])->all(),
    ])->assertSessionHasNoErrors();

    $events = DB::table('platform_outbox')->get();
    expect($events)->toHaveCount(1);
    $event = $events->first();
    $payload = json_decode($event->payload, true);
    $invoice = inTenant(fn () => SupplierInvoice::query()->sole());
    expect($event->audience)->toBe('finance')
        ->and($event->event_type)->toBe('finance.bill.received')
        ->and($event->source_transaction_id)->toBe('supplier_invoice:'.$invoice->id)
        ->and($payload['supplier_id'])->toBe($vendor->id)
        ->and($payload['total_minor'])->toBe(440000)
        ->and($payload['currency'])->toBe('SSP')
        ->and($payload['lines'][0]['expense_account'])->toBe('6100')
        ->and($invoice->finance_status)->toBe('sent');

    // Finance owns it now: no voiding or marking paid here, and nothing is sent twice.
    as_($officer, 'procurement_officer');
    $this->post(p("invoices/{$invoice->id}/paid"), ['paid_on' => now()->toDateString()])->assertSessionHasErrors('invoice');
    $this->post(p("invoices/{$invoice->id}/void"), ['reason' => 'x'])->assertSessionHasErrors('invoice');
    $this->post(p("orders/{$order->id}/close"))->assertSessionHasNoErrors();
    expect(DB::table('platform_outbox')->count())->toBe(1);
});

it('keeps project and funding references without needing Projects', function () {
    actingAsRole('procurement_requester');
    $requisition = requisition(['project_reference' => 'WASH-2026', 'funding_reference' => 'Grant 17']);

    expect($requisition)
        ->project_reference->toBe('WASH-2026')
        ->funding_reference->toBe('Grant 17');
});
