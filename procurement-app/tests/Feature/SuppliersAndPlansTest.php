<?php

use App\Procurement\Models\Plan;
use App\Procurement\Models\Supplier;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('refuses obvious duplicate suppliers', function () {
    actingAsRole('procurement_officer');
    supplier('Juba Stationers Ltd', extra: ['tax_number' => 'TIN 1234']);

    $this->post(p('suppliers'), ['name' => 'JUBA STATIONERS, LIMITED', 'type' => 'company'])->assertSessionHasErrors(['name' => 'This looks like Juba Stationers Ltd, already a supplier.']);
    $this->post(p('suppliers'), ['name' => 'Another', 'type' => 'company', 'tax_number' => 'tin1234'])->assertSessionHasErrors('tax_number');
});

it('never deletes suppliers, and places nothing new with a suspended one', function () {
    ['requester' => $requester, 'approver' => $approver, 'officer' => $officer] = team();
    as_($officer, 'procurement_officer');
    $vendor = supplier('Kilo');

    $this->post(p("suppliers/{$vendor->id}/status"), ['status' => 'suspended'])->assertSessionHasErrors('reason');
    $this->post(p("suppliers/{$vendor->id}/status"), ['status' => 'suspended', 'reason' => 'Under investigation'])->assertSessionHasNoErrors();
    $this->delete(p("suppliers/{$vendor->id}"))->assertStatus(405);

    as_($requester, 'procurement_requester');
    $requisition = requisition();
    as_($approver, 'procurement_department_approver');
    decide("requisitions/{$requisition->id}/decide");

    as_($officer, 'procurement_officer');
    $this->post(p('orders'), ['requisition_id' => $requisition->id, 'supplier_id' => $vendor->id])->assertSessionHasErrors(['supplier_id' => 'Kilo is suspended: nothing new can be placed with them.']);
    expect(inTenant(fn () => Supplier::query()->find($vendor->id)))->not->toBeNull();
});

it('approves a plan and, when required, makes requisitions name one of its items', function () {
    ['requester' => $requester, 'officer' => $officer, 'manager' => $manager] = team();
    settings(['require_plan' => true]);

    as_($officer, 'procurement_officer');
    $this->post(p('plans'), ['title' => 'Head office 2027', 'fiscal_year' => '2027'])->assertSessionHasNoErrors();
    $plan = inTenant(fn () => Plan::query()->sole());
    $this->post(p("plans/{$plan->id}/submit"))->assertSessionHasErrors('plan');
    $this->post(p("plans/{$plan->id}/items"), [
        'description' => 'IT equipment', 'method' => 'rfq', 'quantity' => '1', 'estimated_amount' => '10000', 'funding_reference' => 'Core budget',
    ])->assertSessionHasNoErrors();
    $this->post(p("plans/{$plan->id}/submit"))->assertSessionHasNoErrors();

    as_($requester, 'procurement_requester');
    $draft = requisition(submit: false);
    $this->post(p("requisitions/{$draft->id}/submit"))->assertSessionHasErrors('plan_item_id');

    $item = inTenant(fn () => $plan->items()->sole());
    // Not approved yet.
    $this->put(p("requisitions/{$draft->id}"), [
        'title' => 'Laptops for the finance team', 'justification' => 'x', 'currency' => 'SSP', 'plan_item_id' => $item->id,
        'lines' => [['kind' => 'goods', 'description' => 'Laptop', 'quantity' => '4', 'unit_price' => '1000']],
    ])->assertSessionHasErrors('plan_item_id');

    as_($officer, 'procurement_officer');
    decide("plans/{$plan->id}/decide")->assertForbidden();
    as_($manager, 'procurement_manager');
    decide("plans/{$plan->id}/decide")->assertSessionHasNoErrors();
    expect(inTenant(fn () => $plan->fresh()->status))->toBe('approved');

    as_($requester, 'procurement_requester');
    $this->put(p("requisitions/{$draft->id}"), [
        'title' => 'Laptops for the finance team', 'justification' => 'x', 'currency' => 'SSP', 'plan_item_id' => $item->id,
        'lines' => [['kind' => 'goods', 'description' => 'Laptop', 'quantity' => '4', 'unit_price' => '1000']],
        'submit' => true,
    ])->assertSessionHasNoErrors();
    expect(inTenant(fn () => $draft->fresh()->status))->toBe('pending_approval');

    // A revision sends the plan back to draft as a new revision.
    as_($officer, 'procurement_officer');
    $this->post(p("plans/{$plan->id}/revise"), ['reason' => 'Mid-year review'])->assertSessionHasNoErrors();
    expect(inTenant(fn () => $plan->fresh()))->status->toBe('draft')->revision->toBe(2);
});

it('refuses amounts in a currency the workspace has no rate for', function () {
    actingAsRole('procurement_requester');
    $this->post(p('requisitions'), [
        'title' => 'X', 'justification' => 'Y', 'currency' => 'EUR',
        'lines' => [['kind' => 'goods', 'description' => 'A', 'quantity' => '1', 'unit_price' => '10']],
    ])->assertSessionHasErrors('currency');
});

it('keeps the audit trail append-only on PostgreSQL', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('The trigger exists on PostgreSQL only.');
    }

    actingAsRole('procurement_officer');
    supplier('Lima');
    expect(fn () => DB::table('procurement_audit_events')->update(['action' => 'x']))->toThrow(QueryException::class);
});
