<?php

use App\Logbook\Assignments;
use App\Logbook\Models\AuditEvent;
use App\Logbook\Models\LogResource;
use App\Logbook\Models\LogSheet;
use App\Logbook\Models\ResourceAssignment;

/*
 * ADR-0032 phase 1: managers assign vehicles (any asset) to people; an
 * "assigned only" sheet lets a person record only the assets assigned to
 * them on the day the activity started.
 */

beforeEach(function () {
    $this->travelTo('2026-11-20 10:00:00');
    $this->team = team();
    as_($this->team['manager'], 'logbook_manager');
    $this->post(p('resources'), ['code' => 'VEH-01', 'name' => 'Land Cruiser', 'type' => 'Car'])->assertSessionHasNoErrors();
    $this->post(p('resources'), ['code' => 'VEH-02', 'name' => 'Hilux', 'type' => 'Pickup'])->assertSessionHasNoErrors();
    $this->car = inTenant(fn () => LogResource::query()->where('code', 'VEH-01')->sole());
    $this->pickup = inTenant(fn () => LogResource::query()->where('code', 'VEH-02')->sole());
    $this->logbook = logbook('Transport');
});

function tripFields(): array
{
    return [
        ['name' => 'vehicle', 'label' => 'Vehicle', 'type' => 'resource', 'required' => true, 'resource_type' => 'Vehicles'],
        ['name' => 'purpose', 'label' => 'Purpose', 'type' => 'text', 'required' => true],
    ];
}

function tripSheet($logbook, bool $assignedOnly = true): LogSheet
{
    test()->post(p("logbooks/{$logbook->id}/sheets"), ['name' => 'Vehicle movement', 'code' => 'VM', 'review_required' => false])->assertSessionHasNoErrors();
    $sheet = inTenant(fn () => LogSheet::query()->where('code', 'VM')->sole());
    test()->post(p("sheets/{$sheet->id}/publish"), [
        'fields' => tripFields(),
        'recording' => ['mode' => 'on_demand', 'subject' => 'vehicle', 'assigned_only' => $assignedOnly],
    ])->assertSessionHasNoErrors();

    return inTenant(fn () => $sheet->fresh());
}

function assignTo(LogResource $resource, $user, array $extra = [])
{
    return test()->post(p("resources/{$resource->id}/assignments"), ['user_id' => $user->id, ...$extra]);
}

it('lets a manager assign an asset, with history and an audit trail', function () {
    assignTo($this->car, $this->team['writer'], ['note' => 'Field team'])->assertSessionHasNoErrors();
    assignTo($this->car, $this->team['other'], ['starts_on' => '2026-11-25', 'ends_on' => '2026-11-30'])->assertSessionHasNoErrors();

    expect(inTenant(fn () => Assignments::assetIdsFor($this->team['writer']->id, '2026-11-20')))->toBe([$this->car->id])
        ->and(inTenant(fn () => Assignments::assetIdsFor($this->team['other']->id, '2026-11-20')))->toBe([])
        ->and(inTenant(fn () => Assignments::assetIdsFor($this->team['other']->id, '2026-11-30')))->toBe([$this->car->id])
        ->and(inTenant(fn () => Assignments::assetIdsFor($this->team['other']->id, '2026-12-01')))->toBe([]);

    $this->get(p("resources/{$this->car->id}"))->assertInertia(fn ($page) => $page
        ->has('assignments', 2)
        ->where('assignments', fn ($rows) => collect($rows)->pluck('state')->sort()->values()->all() === ['current', 'upcoming'])
        ->has('members'));
    expect(inTenant(fn () => AuditEvent::query()->where('action', 'resource.assigned')->count()))->toBe(2);
});

it('refuses overlapping, backwards or foreign assignments', function () {
    assignTo($this->car, $this->team['writer'])->assertSessionHasNoErrors();
    assignTo($this->car, $this->team['writer'], ['starts_on' => '2026-12-01'])->assertSessionHasErrors('user_id');
    assignTo($this->pickup, $this->team['writer'], ['starts_on' => '2026-11-25', 'ends_on' => '2026-11-24'])->assertSessionHasErrors('ends_on');
    assignTo($this->pickup, $this->team['writer'], ['starts_on' => 'tomorrow'])->assertSessionHasErrors('starts_on');
    $this->post(p("resources/{$this->pickup->id}/assignments"), ['user_id' => '0198f0c0-0000-7000-8000-000000000000'])->assertSessionHasErrors('user_id');
    // Several drivers on one vehicle, several vehicles for one driver: both fine.
    assignTo($this->car, $this->team['other'])->assertSessionHasNoErrors();
    assignTo($this->pickup, $this->team['writer'])->assertSessionHasNoErrors();
    expect(inTenant(fn () => ResourceAssignment::query()->count()))->toBe(3);
});

it('ends an assignment today and cancels one not started yet', function () {
    assignTo($this->car, $this->team['writer'])->assertSessionHasNoErrors();
    assignTo($this->pickup, $this->team['writer'], ['starts_on' => '2026-11-28'])->assertSessionHasNoErrors();
    [$current, $upcoming] = inTenant(fn () => [
        ResourceAssignment::query()->where('log_resource_id', $this->car->id)->sole(),
        ResourceAssignment::query()->where('log_resource_id', $this->pickup->id)->sole(),
    ]);

    $this->post(p("resources/{$this->car->id}/assignments/{$current->id}/end"))->assertSessionHasNoErrors();
    $this->post(p("resources/{$this->pickup->id}/assignments/{$upcoming->id}/end"))->assertSessionHasNoErrors();
    // Twice: refused. Through another asset's address: not found.
    $this->post(p("resources/{$this->car->id}/assignments/{$current->id}/end"))->assertSessionHasErrors('assignment');
    $this->post(p("resources/{$this->car->id}/assignments/{$upcoming->id}/end"))->assertNotFound();

    $writer = $this->team['writer']->id;
    expect(inTenant(fn () => Assignments::assetIdsFor($writer, '2026-11-20')))->toBe([$this->car->id])
        ->and(inTenant(fn () => Assignments::assetIdsFor($writer, '2026-11-21')))->toBe([])
        ->and(inTenant(fn () => Assignments::assetIdsFor($writer, '2026-11-28')))->toBe([])
        ->and(inTenant(fn () => AuditEvent::query()->where('action', 'resource.assignment_ended')->count()))->toBe(1)
        ->and(inTenant(fn () => AuditEvent::query()->where('action', 'resource.assignment_cancelled')->count()))->toBe(1);
    // Cancelled covers nothing, so the same days can be assigned again.
    assignTo($this->pickup, $this->team['writer'], ['starts_on' => '2026-11-28'])->assertSessionHasNoErrors();
});

it('lets only managers assign', function () {
    as_($this->team['writer'], 'logbook_contributor');
    assignTo($this->car, $this->team['writer'])->assertForbidden();
    $this->get(p("resources/{$this->car->id}"))->assertOk()->assertInertia(fn ($page) => $page->where('members', []));
    expect(inTenant(fn () => ResourceAssignment::query()->count()))->toBe(0);
});

it('lets a person record only the vehicles assigned to them that day', function () {
    $sheet = tripSheet($this->logbook);
    assignTo($this->car, $this->team['writer'], ['starts_on' => '2026-11-19'])->assertSessionHasNoErrors();
    as_($this->team['writer'], 'logbook_contributor');

    // The form offers only the assigned vehicle.
    $this->get(p("entries/new?sheet={$sheet->id}"))->assertInertia(fn ($page) => $page
        ->where('fields.0.assigned_only', true)
        ->where('fields.0.resource_ids', [$this->car->id]));

    writeEntry($sheet, ['vehicle' => $this->pickup->id, 'purpose' => 'Supplies'])->assertSessionHasErrors('values.vehicle');
    writeEntry($sheet, ['vehicle' => $this->car->id, 'purpose' => 'Supplies'])->assertSessionHasNoErrors();
    expect(latestEntry()->status)->toBe('finalized');

    // Someone with no assignment records nothing; a draft is kept but cannot be sent.
    as_($this->team['other'], 'logbook_contributor');
    writeEntry($sheet, ['vehicle' => $this->car->id, 'purpose' => 'Supplies'])->assertSessionHasErrors('values.vehicle');
    writeEntry($sheet, ['vehicle' => $this->car->id, 'purpose' => 'Supplies'], submit: false)->assertSessionHasNoErrors();
    $draft = latestEntry();
    $this->post(p("entries/{$draft->id}/submit"))->assertSessionHasErrors('values.vehicle');
});

it('checks the day the entry happened, not the day it was sent', function () {
    $sheet = tripSheet($this->logbook);
    // Assigned on the 18th and 19th only; the entry is for the 19th (writeEntry), sent on the 20th.
    assignTo($this->car, $this->team['writer'], ['starts_on' => '2026-11-18', 'ends_on' => '2026-11-19'])->assertSessionHasNoErrors();
    as_($this->team['writer'], 'logbook_contributor');
    writeEntry($sheet, ['vehicle' => $this->car->id, 'purpose' => 'Supplies'])->assertSessionHasNoErrors();
    $this->post(p('entries'), [
        'sheet_id' => $sheet->id, 'occurred_at' => '2026-11-20T08:30', 'values' => ['vehicle' => $this->car->id, 'purpose' => 'Supplies'],
        'idempotency_key' => (string) Str::uuid7(), 'submit' => true,
    ])->assertSessionHasErrors('values.vehicle');
});

it('leaves sheets that are not assigned only as they were', function () {
    $sheet = tripSheet($this->logbook, assignedOnly: false);
    as_($this->team['writer'], 'logbook_contributor');
    $this->get(p("entries/new?sheet={$sheet->id}"))->assertInertia(fn ($page) => $page->missing('fields.0.assigned_only'));
    writeEntry($sheet, ['vehicle' => $this->pickup->id, 'purpose' => 'Supplies'])->assertSessionHasNoErrors();
});

it('refuses assigned only on a sheet that names no asset', function () {
    $this->post(p("logbooks/{$this->logbook->id}/sheets"), ['name' => 'Notes', 'code' => 'NOTE'])->assertSessionHasNoErrors();
    $sheet = inTenant(fn () => LogSheet::query()->where('code', 'NOTE')->sole());
    $this->post(p("sheets/{$sheet->id}/publish"), [
        'fields' => [['name' => 'note', 'label' => 'Note', 'type' => 'text', 'required' => true]],
        'recording' => ['mode' => 'on_demand', 'assigned_only' => true],
    ])->assertSessionHasErrors('recording.assigned_only');
});

it('gives the phone its assignments and the sheet setting', function () {
    $sheet = tripSheet($this->logbook);
    assignTo($this->car, $this->team['writer'])->assertSessionHasNoErrors();
    assignTo($this->pickup, $this->team['writer'], ['starts_on' => '2026-11-01', 'ends_on' => '2026-11-10'])->assertSessionHasNoErrors();
    $this->actingAsAppMember('acme', rolePermissions('logbook_contributor'), user: $this->team['writer']);

    $kit = $this->getJson($this->workspaceUrl('acme', 'api/v1/kit'))->assertOk()->json();
    expect(collect($kit['sheets'])->firstWhere('id', $sheet->id)['assignedOnly'])->toBeTrue()
        ->and($kit['assignments'])->toBe([['resourceId' => $this->car->id, 'from' => '2026-11-20', 'to' => null]]);
});
