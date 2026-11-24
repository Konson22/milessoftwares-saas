<?php

use App\Logbook\Assignments;
use App\Logbook\Models\AuditEvent;
use App\Logbook\Models\Dispatch;
use App\Logbook\Models\LogResource;
use App\Logbook\Models\LogSheet;
use App\Logbook\Models\Notification;
use Illuminate\Support\Str;

/*
 * ADR-0032 phase 2: managers plan trips (vehicle, driver, day); the driver
 * starts one, which makes its entry; recording the entry finishes it. A
 * planned trip assigns the vehicle to the driver for its day.
 */

beforeEach(function () {
    $this->travelTo('2026-11-20 10:00:00');
    $this->team = team();
    as_($this->team['manager'], 'logbook_manager');
    $this->post(p('resources'), ['code' => 'VEH-01', 'name' => 'Land Cruiser', 'type' => 'Car'])->assertSessionHasNoErrors();
    $this->post(p('resources'), ['code' => 'VEH-02', 'name' => 'Hilux', 'type' => 'Pickup'])->assertSessionHasNoErrors();
    $this->post(p('resources'), ['code' => 'GEN-01', 'name' => 'Generator', 'type' => 'Diesel generator'])->assertSessionHasNoErrors();
    $this->car = inTenant(fn () => LogResource::query()->where('code', 'VEH-01')->sole());
    $this->pickup = inTenant(fn () => LogResource::query()->where('code', 'VEH-02')->sole());
    $this->generator = inTenant(fn () => LogResource::query()->where('code', 'GEN-01')->sole());
    $this->logbook = logbook('Transport');

    $this->post(p("logbooks/{$this->logbook->id}/sheets"), ['name' => 'Vehicle movement', 'code' => 'VM', 'review_required' => false])->assertSessionHasNoErrors();
    $this->sheet = inTenant(fn () => LogSheet::query()->where('code', 'VM')->sole());
    $this->post(p("sheets/{$this->sheet->id}/publish"), [
        'fields' => [
            ['name' => 'vehicle', 'label' => 'Vehicle', 'type' => 'resource', 'required' => true, 'resource_type' => 'Vehicles'],
            ['name' => 'purpose', 'label' => 'Purpose', 'type' => 'text', 'required' => true],
        ],
        'recording' => ['mode' => 'on_demand', 'subject' => 'vehicle', 'assigned_only' => true],
    ])->assertSessionHasNoErrors();
});

function planTrip(array $extra = [])
{
    return test()->post(p('trips'), [
        'sheet_id' => test()->sheet->id, 'resource_id' => test()->car->id, 'user_id' => test()->team['writer']->id,
        'planned_on' => '2026-11-20', 'planned_time' => '14:00', 'destination' => 'Wau hospital', 'purpose' => 'Vaccine delivery', ...$extra,
    ]);
}

function startTrip(Dispatch $dispatch, array $values = [], bool $submit = false, string $at = '2026-11-20T14:05')
{
    return test()->post(p('entries'), [
        'sheet_id' => test()->sheet->id, 'occurred_at' => $at, 'dispatch_id' => $dispatch->id,
        'values' => ['vehicle' => test()->car->id, 'purpose' => 'Vaccine delivery', ...$values],
        'idempotency_key' => (string) Str::uuid7(), 'submit' => $submit,
    ]);
}

it('plans a trip, tells the driver, and assigns the vehicle for that day', function () {
    planTrip()->assertSessionHasNoErrors();
    $trip = inTenant(fn () => Dispatch::query()->sole());
    expect($trip->status)->toBe('planned')->and($trip->planned_time)->toBe('14:00')
        ->and(inTenant(fn () => Assignments::assetIdsFor($this->team['writer']->id, '2026-11-20')))->toBe([$this->car->id])
        ->and(inTenant(fn () => Assignments::assetIdsFor($this->team['writer']->id, '2026-11-21')))->toBe([])
        ->and(inTenant(fn () => AuditEvent::query()->where('action', 'dispatch.planned')->count()))->toBe(1)
        ->and(inTenant(fn () => Notification::query()->where('user_id', $this->team['writer']->id)->where('event', 'dispatch.planned')->count()))->toBe(1);

    // The driver sees it; another contributor does not.
    as_($this->team['writer'], 'logbook_contributor');
    $this->get(p('trips'))->assertInertia(fn ($page) => $page->component('trips/index')->has('trips', 1)
        ->where('trips.0.destination', 'Wau hospital')->where('trips.0.canCancel', false)->where('canPlan', false));
    as_($this->team['other'], 'logbook_contributor');
    $this->get(p('trips'))->assertInertia(fn ($page) => $page->has('trips', 0));
});

it('refuses trips that cannot be driven', function () {
    planTrip(['resource_id' => $this->generator->id])->assertSessionHasErrors('resource_id');
    planTrip(['user_id' => $this->team['viewer']->id])->assertSessionHasErrors('user_id');
    planTrip(['planned_on' => '2026-11-19'])->assertSessionHasErrors('planned_on');
    planTrip(['planned_on' => 'Friday'])->assertSessionHasErrors('planned_on');
    planTrip(['planned_time' => '25:00'])->assertSessionHasErrors('planned_time');
    expect(inTenant(fn () => Dispatch::query()->count()))->toBe(0);
});

it('lets only managers plan and cancel', function () {
    as_($this->team['writer'], 'logbook_contributor');
    planTrip()->assertForbidden();
    as_($this->team['manager'], 'logbook_manager');
    planTrip()->assertSessionHasNoErrors();
    $trip = inTenant(fn () => Dispatch::query()->sole());
    as_($this->team['writer'], 'logbook_contributor');
    $this->post(p("trips/{$trip->id}/cancel"))->assertForbidden();
});

it('starts, then finishes a trip through its entry', function () {
    planTrip()->assertSessionHasNoErrors();
    $trip = inTenant(fn () => Dispatch::query()->sole());
    as_($this->team['writer'], 'logbook_contributor');

    // The form opens with the planned vehicle chosen.
    $this->get(p("entries/new?sheet={$this->sheet->id}&dispatch={$trip->id}"))->assertInertia(fn ($page) => $page
        ->where('values.vehicle', $this->car->id)->where('dispatch.id', $trip->id));

    startTrip($trip)->assertSessionHasNoErrors();
    $entry = latestEntry();
    expect($entry->dispatch_id)->toBe($trip->id)
        ->and(inTenant(fn () => $trip->fresh()->status))->toBe('started');
    // A second entry for the same trip: refused.
    startTrip($trip)->assertSessionHasErrors('dispatch_id');

    // Recorded with another vehicle: refused, even one assigned to them.
    $this->put(p("entries/{$entry->id}"), ['occurred_at' => '2026-11-20T14:05', 'values' => ['vehicle' => $this->pickup->id, 'purpose' => 'x']]);
    $this->post(p("entries/{$entry->id}/submit"))->assertSessionHasErrors('values.vehicle');
    $this->put(p("entries/{$entry->id}"), ['occurred_at' => '2026-11-20T14:05', 'values' => ['vehicle' => $this->car->id, 'purpose' => 'Vaccine delivery']])->assertSessionHasNoErrors();
    $this->post(p("entries/{$entry->id}/submit"))->assertSessionHasNoErrors();
    expect(inTenant(fn () => $trip->fresh()->status))->toBe('done')
        ->and(latestEntry()->status)->toBe('finalized');

    $this->get(p("entries/{$entry->id}"))->assertInertia(fn ($page) => $page->where('dispatch.destination', 'Wau hospital'));
});

it('puts a trip back to planned when its draft is discarded', function () {
    planTrip()->assertSessionHasNoErrors();
    $trip = inTenant(fn () => Dispatch::query()->sole());
    as_($this->team['writer'], 'logbook_contributor');
    startTrip($trip)->assertSessionHasNoErrors();
    $this->delete(p('entries/'.latestEntry()->id))->assertSessionHasNoErrors();
    expect(inTenant(fn () => $trip->fresh()))->status->toBe('planned')->log_entry_id->toBeNull();
    startTrip($trip, submit: true)->assertSessionHasNoErrors();
    expect(inTenant(fn () => $trip->fresh()->status))->toBe('done');
});

it('lets nobody else record a driver’s trip', function () {
    planTrip()->assertSessionHasNoErrors();
    $trip = inTenant(fn () => Dispatch::query()->sole());
    as_($this->team['other'], 'logbook_contributor');
    startTrip($trip)->assertSessionHasErrors('dispatch_id');
});

it('cancels a planned trip, and no longer once started', function () {
    planTrip()->assertSessionHasNoErrors();
    planTrip(['planned_on' => '2026-11-21'])->assertSessionHasNoErrors();
    [$today, $tomorrow] = inTenant(fn () => Dispatch::query()->orderBy('planned_on')->get()->all());

    $this->post(p("trips/{$tomorrow->id}/cancel"), ['reason' => 'Vehicle in the garage'])->assertSessionHasNoErrors();
    expect(inTenant(fn () => $tomorrow->fresh()))->status->toBe('cancelled')->cancel_reason->toBe('Vehicle in the garage')
        ->and(inTenant(fn () => Assignments::assetIdsFor($this->team['writer']->id, '2026-11-21')))->toBe([])
        ->and(inTenant(fn () => Notification::query()->where('event', 'dispatch.cancelled')->count()))->toBe(1);
    $this->post(p("trips/{$tomorrow->id}/cancel"))->assertSessionHasErrors('dispatch');

    as_($this->team['writer'], 'logbook_contributor');
    startTrip($today)->assertSessionHasNoErrors();
    as_($this->team['manager'], 'logbook_manager');
    $this->post(p("trips/{$today->id}/cancel"))->assertSessionHasErrors('dispatch');
});

it('gives the phone the driver’s open trips, and takes a trip from it', function () {
    planTrip()->assertSessionHasNoErrors();
    $trip = inTenant(fn () => Dispatch::query()->sole());
    $this->actingAsAppMember('acme', rolePermissions('logbook_contributor'), user: $this->team['writer']);
    $kit = $this->getJson($this->workspaceUrl('acme', 'api/v1/kit'))->assertOk()->json();
    expect($kit['dispatches'])->toHaveCount(1)
        ->and($kit['dispatches'][0])->toMatchArray(['id' => $trip->id, 'status' => 'planned', 'day' => '2026-11-20', 'resourceId' => $this->car->id])
        ->and($kit['assignments'])->toBe([]);
});
