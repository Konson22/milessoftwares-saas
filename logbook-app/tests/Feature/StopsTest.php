<?php

use App\Logbook\Models\EntryRevision;
use App\Logbook\Models\LogResource;
use App\Logbook\Models\LogSheet;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
 * ADR-0032 phase 3: a vehicle trip keeps the points it stopped at, checked
 * against the trip's times and odometer readings when it is recorded.
 */

beforeEach(function () {
    $this->travelTo('2026-11-20 18:00:00');
    $this->team = team();
    as_($this->team['manager'], 'logbook_manager');
    $this->post(p('resources'), ['code' => 'VEH-01', 'name' => 'Land Cruiser', 'type' => 'Car'])->assertSessionHasNoErrors();
    $this->car = inTenant(fn () => LogResource::query()->where('code', 'VEH-01')->sole());
    $this->logbook = logbook('Transport');
    $this->sheet = stopsSheet($this->logbook, 'required');
    as_($this->team['writer'], 'logbook_contributor');
});

function stopsSheet($logbook, string $stops, string $code = 'VM', string $mode = 'event'): LogSheet
{
    test()->post(p("logbooks/{$logbook->id}/sheets"), ['name' => "Trips {$code}", 'code' => $code, 'review_required' => false])->assertSessionHasNoErrors();
    $sheet = inTenant(fn () => LogSheet::query()->where('code', $code)->sole());
    test()->post(p("sheets/{$sheet->id}/publish"), [
        'fields' => [
            ['name' => 'vehicle', 'label' => 'Vehicle', 'type' => 'resource', 'required' => true, 'resource_type' => 'Vehicles'],
            ['name' => 'start_km', 'label' => 'Odometer at start', 'type' => 'integer', 'required' => true],
            ['name' => 'end_km', 'label' => 'Odometer at end', 'type' => 'integer', 'required' => true],
        ],
        'recording' => ['mode' => $mode, 'subject' => 'vehicle', 'stops' => $stops, 'activity' => $mode === 'event' ? [
            'resource_field' => 'vehicle', 'measure' => 'odometer', 'start_field' => 'start_km', 'end_field' => 'end_km',
        ] : null],
    ]);

    return inTenant(fn () => $sheet->fresh());
}

/** A trip from 08:00 to 17:00, 10 000 km to 10 180 km, workspace time. */
function tripWithStops(array $stops, bool $submit = true, array $extra = [])
{
    return test()->post(p('entries'), [
        'sheet_id' => test()->sheet->id, 'occurred_at' => '2026-11-20T08:00', 'activity_started_at' => '2026-11-20T08:00', 'activity_ended_at' => '2026-11-20T17:00',
        'values' => ['vehicle' => test()->car->id, 'start_km' => '10000', 'end_km' => '10180'],
        'stops' => $stops, 'idempotency_key' => (string) Str::uuid7(), 'submit' => $submit, ...$extra,
    ]);
}

function tripStop(string $place, string $arrived, ?string $departed = null, ?string $odometer = null, array $extra = []): array
{
    return ['place' => $place, 'arrived_at' => "2026-11-20T{$arrived}", 'departed_at' => $departed ? "2026-11-20T{$departed}" : null, 'odometer' => $odometer, ...$extra];
}

it('keeps the stops of a trip, on the entry and its revision', function () {
    tripWithStops([
        tripStop('Wau hospital', '09:10', '09:40', '10045', ['purpose' => 'Vaccine delivery', 'pin' => ['lat' => 7.7, 'lng' => 28.0, 'accuracy_m' => 12]]),
        tripStop('Jur River school', '11:00', '12:15', '10110'),
        tripStop('Fuel station', '15:30', null, null, ['note' => 'Queue']),
    ])->assertSessionHasNoErrors();
    $entry = latestEntry();
    expect($entry->status)->toBe('finalized')->and($entry->stops)->toHaveCount(3)
        ->and($entry->stops[0])->toMatchArray(['place' => 'Wau hospital', 'odometer' => 10045.0, 'purpose' => 'Vaccine delivery'])
        ->and($entry->stops[0]['pin'])->toEqual(['lat' => 7.7, 'lng' => 28, 'accuracy_m' => 12])
        ->and(inTenant(fn () => EntryRevision::query()->where('log_entry_id', $entry->id)->sole()->stops))->toHaveCount(3);

    $this->get(p("entries/{$entry->id}"))->assertInertia(fn ($page) => $page
        ->has('stops', 3)
        ->where('stops.0.arrivedAt', '2026-11-20 09:10')
        ->where('stops.0.legKm', 45)
        ->where('stops.0.minutes', 30)
        ->where('stops.1.legKm', 65)
        ->where('stops.2.legKm', null));
});

it('checks the shape of each stop whenever it is saved', function () {
    tripWithStops([['place' => '', 'arrived_at' => '']], submit: false)->assertSessionHasErrors(['stops.0.place', 'stops.0.arrived_at']);
    tripWithStops([tripStop('A', '10:00', '09:00')], submit: false)->assertSessionHasErrors('stops.0.departed_at');
    tripWithStops([tripStop('A', '10:00', null, 'many')], submit: false)->assertSessionHasErrors('stops.0.odometer');
    tripWithStops([tripStop('A', '10:00', null, null, ['pin' => ['lat' => 200, 'lng' => 0]])], submit: false)->assertSessionHasErrors('stops.0.pin');
    tripWithStops(array_fill(0, 51, tripStop('A', '10:00')), submit: false)->assertSessionHasErrors('stops');
});

it('refuses stops outside the trip, out of order, or with the odometer going down', function () {
    tripWithStops([tripStop('Early', '07:30')])->assertSessionHasErrors('stops.0.arrived_at');
    tripWithStops([tripStop('Late', '16:30', '17:30')])->assertSessionHasErrors('stops.0.arrived_at');
    tripWithStops([tripStop('B', '11:00'), tripStop('A', '10:00')])->assertSessionHasErrors('stops.1.arrived_at');
    tripWithStops([tripStop('A', '10:00', null, '9990')])->assertSessionHasErrors('stops.0.odometer');
    tripWithStops([tripStop('A', '10:00', null, '10100'), tripStop('B', '11:00', null, '10050')])->assertSessionHasErrors('stops.1.odometer');
    tripWithStops([tripStop('A', '10:00', null, '10200')])->assertSessionHasErrors('stops.0.odometer');
});

it('asks for at least one stop when the sheet requires them, and keeps drafts', function () {
    tripWithStops([])->assertSessionHasErrors('stops');
    // A draft is saved without stops, then stops are added as the trip goes.
    tripWithStops([], submit: false)->assertSessionHasNoErrors();
    $entry = latestEntry();
    $this->put(p("entries/{$entry->id}"), [
        'occurred_at' => '2026-11-20T08:00', 'activity_started_at' => '2026-11-20T08:00', 'activity_ended_at' => '2026-11-20T17:00',
        'values' => ['vehicle' => $this->car->id, 'start_km' => '10000', 'end_km' => '10180'],
        'stops' => [tripStop('Wau hospital', '09:10')],
    ])->assertSessionHasNoErrors();
    expect(latestEntry()->stops)->toHaveCount(1);
    // A save that sends no stops keeps them.
    $this->put(p("entries/{$entry->id}"), [
        'occurred_at' => '2026-11-20T08:00', 'values' => ['vehicle' => $this->car->id, 'start_km' => '10000', 'end_km' => '10180'],
    ])->assertSessionHasNoErrors();
    expect(latestEntry()->stops)->toHaveCount(1);
    $this->post(p("entries/{$entry->id}/submit"))->assertSessionHasNoErrors();
    expect(latestEntry()->status)->toBe('finalized');
});

it('ignores stops on sheets that do not take them, and allows none when optional', function () {
    as_($this->team['manager'], 'logbook_manager');
    $optional = stopsSheet($this->logbook, 'optional', 'OPT');
    $none = stopsSheet($this->logbook, 'off', 'OFF');
    as_($this->team['writer'], 'logbook_contributor');
    $this->sheet = $optional;
    tripWithStops([])->assertSessionHasNoErrors();
    $this->sheet = $none;
    // The same vehicle again: its odometer carries on from the last trip.
    tripWithStops([tripStop('A', '10:00')], extra: ['values' => ['vehicle' => $this->car->id, 'start_km' => '10180', 'end_km' => '10300']])->assertSessionHasNoErrors();
    expect(latestEntry()->stops)->toBeNull();
});

it('records stops only on event-based sheets', function () {
    as_($this->team['manager'], 'logbook_manager');
    $this->post(p("logbooks/{$this->logbook->id}/sheets"), ['name' => 'Checks', 'code' => 'CHK'])->assertSessionHasNoErrors();
    $sheet = inTenant(fn () => LogSheet::query()->where('code', 'CHK')->sole());
    $this->post(p("sheets/{$sheet->id}/publish"), [
        'fields' => [['name' => 'note', 'label' => 'Note', 'type' => 'text', 'required' => true]],
        'recording' => ['mode' => 'on_demand', 'stops' => 'optional'],
    ])->assertSessionHasErrors('recording.stops');
});

it('takes stops from the phone with their offsets, and gives them back', function () {
    $this->actingAsAppMember('acme', rolePermissions('logbook_contributor'), user: $this->team['writer']);
    $url = fn (string $path) => $this->workspaceUrl('acme', 'api/v1/'.$path);
    $kit = $this->getJson($url('kit'))->assertOk()->json('sheets');
    expect(collect($kit)->firstWhere('id', $this->sheet->id)['recording']['stops'])->toBe('required');

    $tz = inTenant(fn () => Workspace::current()->timezone ?? 'UTC');
    $at = fn (string $hm) => CarbonImmutable::parse("2026-11-20 {$hm}", $tz)->toIso8601String();
    $entry = $this->postJson($url('entries'), [
        'id' => (string) Str::uuid7(), 'sheet_id' => $this->sheet->id, 'version_id' => $this->sheet->published_version_id,
        'occurred_at' => $at('08:00'), 'activity_started_at' => $at('08:00'), 'activity_ended_at' => $at('17:00'),
        'values' => ['vehicle' => $this->car->id, 'start_km' => 10000, 'end_km' => 10180],
        'stops' => [['place' => 'Wau hospital', 'arrived_at' => $at('09:10'), 'departed_at' => $at('09:40'), 'odometer' => 10045]],
        'submit' => true,
    ])->assertCreated()->json();
    expect($entry['stops'])->toHaveCount(1)->and($entry['stops'][0]['place'])->toBe('Wau hospital')
        ->and(CarbonImmutable::parse($entry['stops'][0]['arrived_at'])->equalTo(CarbonImmutable::parse($at('09:10'))))->toBeTrue();
});

it('exports the stops of the trips, one line per stop', function () {
    tripWithStops([tripStop('Wau hospital', '09:10', '09:40', '10045', ['purpose' => 'Vaccine delivery']), tripStop('Jur River school', '11:00', null, '10110')])->assertSessionHasNoErrors();
    as_($this->team['manager'], 'logbook_manager');
    $csv = $this->get(p('reports/stops.csv'))->assertOk()->streamedContent();
    $lines = array_values(array_filter(explode("\n", trim($csv))));
    expect($lines)->toHaveCount(3)
        ->and($lines[1])->toContain('Wau hospital')->toContain('Vaccine delivery')->toContain('2026-11-20 09:10')->toContain(',30,')
        ->and($lines[2])->toContain('Jur River school')->toContain(',65,');
});
