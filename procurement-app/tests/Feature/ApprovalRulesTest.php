<?php

use App\Procurement\Models\Approval;
use App\Procurement\Models\Requisition;

/*
 * Approval rules are configured per organization, never hardcoded: amount
 * bands in the workspace currency, departments, methods and ordered steps.
 */

beforeEach(function () {
    $this->team = team();
    $this->admin = actingAsRole('procurement_administrator', name: 'Admin');
});

function rule(array $values): void
{
    test()->post(p('settings/rules'), [
        'document_type' => 'requisition',
        'name' => 'Rule',
        'active' => true,
        ...$values,
    ])->assertSessionHasNoErrors();
}

it('applies the amount band that fits, with its steps in order', function () {
    ['requester' => $requester, 'approver' => $approver, 'manager' => $manager] = $this->team;
    $director = actingAsRole('procurement_department_approver', name: 'Director');

    as_($this->admin, 'procurement_administrator');
    rule(['name' => 'Small', 'min_amount' => '0', 'max_amount' => '1000', 'steps' => [['type' => 'permission', 'value' => 'procurement.requisitions.approve']]]);
    rule(['name' => 'Large', 'min_amount' => '1000.01', 'steps' => [
        ['type' => 'permission', 'value' => 'procurement.requisitions.approve'],
        ['type' => 'member', 'value' => $director->id],
    ]]);

    as_($requester, 'procurement_requester');
    $requisition = requisition(); // 4,400 SSP: the large band

    expect(inTenant(fn () => Approval::query()->where('document_id', $requisition->id)->orderBy('step')->pluck('approver_label')->all()))
        ->toBe(['Requisition approvers', 'Director']);

    as_($approver, 'procurement_department_approver');
    decide("requisitions/{$requisition->id}/decide")->assertSessionHasNoErrors();
    expect(inTenant(fn () => $requisition->fresh()->status))->toBe('pending_approval');

    // The second step is the Director's alone, and the first approver cannot take it either.
    decide("requisitions/{$requisition->id}/decide")->assertSessionHasErrors('decision');
    as_($manager, 'procurement_manager');
    decide("requisitions/{$requisition->id}/decide")->assertSessionHasErrors(['decision' => 'This step waits for Director.']);

    as_($director, 'procurement_department_approver');
    decide("requisitions/{$requisition->id}/decide")->assertSessionHasNoErrors();
    expect(inTenant(fn () => $requisition->fresh()->status))->toBe('approved');

    // A small one needs one step.
    as_($requester, 'procurement_requester');
    $small = requisition(['title' => 'Pens', 'lines' => [['kind' => 'goods', 'description' => 'Pens', 'quantity' => '10', 'unit_price' => '5']]]);
    expect(inTenant(fn () => Approval::query()->where('document_id', $small->id)->count()))->toBe(1);
});

it('never lets one person approve their own requisition, unless settings allow it', function () {
    $both = actingAsRole('procurement_department_approver', name: 'Both');
    $requisition = requisition();

    decide("requisitions/{$requisition->id}/decide")->assertSessionHasErrors(['decision' => 'You submitted this, so someone else must decide it.']);
    expect(inTenant(fn () => $requisition->fresh()->status))->toBe('pending_approval');

    settings(['allow_self_approval' => true]);
    decide("requisitions/{$requisition->id}/decide")->assertSessionHasNoErrors();
    expect(inTenant(fn () => $requisition->fresh()->status))->toBe('approved');
});

it('never lets one person decide two steps of the same submission', function () {
    ['requester' => $requester, 'approver' => $approver] = $this->team;
    as_($this->admin, 'procurement_administrator');
    rule(['name' => 'Two approvers', 'steps' => [
        ['type' => 'permission', 'value' => 'procurement.requisitions.approve'],
        ['type' => 'permission', 'value' => 'procurement.requisitions.approve'],
    ]]);

    as_($requester, 'procurement_requester');
    $requisition = requisition();
    as_($approver, 'procurement_department_approver');
    decide("requisitions/{$requisition->id}/decide")->assertSessionHasNoErrors();
    decide("requisitions/{$requisition->id}/decide")->assertSessionHasErrors(['decision' => 'You decided an earlier step of this submission; the next step needs someone else.']);
});

it('prefers a department rule and keeps waiting steps when rules change', function () {
    ['requester' => $requester, 'approver' => $approver] = $this->team;
    $finance = $this->platform()->department('acme', 'Finance');
    $head = actingAsRole('procurement_department_approver', name: 'Head of Finance');

    as_($this->admin, 'procurement_administrator');
    rule(['name' => 'Everyone', 'steps' => [['type' => 'permission', 'value' => 'procurement.requisitions.approve']]]);
    rule(['name' => 'Finance', 'department_id' => $finance, 'steps' => [['type' => 'member', 'value' => $head->id]]]);

    as_($requester, 'procurement_requester');
    $requisition = requisition(['department_id' => $finance]);
    expect(inTenant(fn () => $requisition->fresh()->department_name))->toBe('Finance');

    as_($approver, 'procurement_department_approver');
    decide("requisitions/{$requisition->id}/decide")->assertSessionHasErrors('decision');
    as_($head, 'procurement_department_approver');
    decide("requisitions/{$requisition->id}/decide")->assertSessionHasNoErrors();

    // Unknown department ids are refused.
    as_($requester, 'procurement_requester');
    $this->post(p('requisitions'), [
        'title' => 'X', 'justification' => 'Y', 'currency' => 'SSP', 'department_id' => 'not-ours',
        'lines' => [['kind' => 'goods', 'description' => 'A', 'quantity' => '1', 'unit_price' => '1']],
    ])->assertSessionHasErrors('department_id');
});

it('rejects with a reason, and keeps every decision', function () {
    ['requester' => $requester, 'approver' => $approver] = $this->team;
    as_($requester, 'procurement_requester');
    $requisition = requisition();

    as_($approver, 'procurement_department_approver');
    decide("requisitions/{$requisition->id}/decide", 'reject', 'Not in this year\'s plan.')->assertSessionHasNoErrors();

    expect(inTenant(fn () => Requisition::query()->find($requisition->id)))
        ->status->toBe('rejected')
        ->status_reason->toBe('Not in this year\'s plan.');
    expect(inTenant(fn () => Approval::query()->where('document_id', $requisition->id)->sole()))
        ->status->toBe('rejected')
        ->decided_by->toBe($approver->id);
});

it('lets only settings managers write approval rules, and refuses outsiders as members', function () {
    ['officer' => $officer] = $this->team;
    as_($officer, 'procurement_officer');
    $this->post(p('settings/rules'), ['document_type' => 'requisition', 'name' => 'X', 'steps' => [['type' => 'permission', 'value' => 'procurement.requisitions.approve']]])->assertForbidden();

    as_($this->admin, 'procurement_administrator');
    $this->post(p('settings/rules'), ['document_type' => 'order', 'name' => 'X', 'steps' => [['type' => 'member', 'value' => (string) str()->uuid()]]])
        ->assertSessionHasErrors('steps.0.value');
});
