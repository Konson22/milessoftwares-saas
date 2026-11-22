<?php

use App\Logbook\Models\AuditEvent;
use App\Logbook\Models\Entry;
use App\Logbook\Models\LogResource;
use App\Logbook\Models\LogSheet;
use App\Logbook\Models\LogSheetVersion;
use App\Logbook\Models\Track;
use App\Logbook\Models\TrackPoint;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/*
 * Optional GPS (ADR-0028): per-sheet location modes, routes recorded on
 * the phone and sent in repeatable batches, entries linked to them, who
 * may see them, retention, and the coming-soon pages.
 */

function gps(string $path = '', string $slug = 'acme'): string
{
    return test()->workspaceUrl($slug, 'api/v1/'.ltrim($path, '/'));
}

function onGpsPhone(User $user, string $role, string $slug = 'acme'): void
{
    test()->actingAsAppMember($slug, rolePermissions($role), user: $user);
    Cache::flush();
}

/** A published sheet with the given location settings. */
function locatedSheet(string $code, array $location): LogSheet
{
    test()->post(p("logbooks/{$GLOBALS['gpsLogbook']}/sheets"), ['name' => "{$code} log", 'code' => $code, 'review_required' => false])->assertSessionHasNoErrors();
    $sheet = inTenant(fn () => LogSheet::query()->where('code', $code)->firstOrFail());
    test()->post(p("sheets/{$sheet->id}/publish"), ['fields' => generatorFields(), 'location' => $location])->assertSessionHasNoErrors();

    return inTenant(fn () => $sheet->fresh(['publishedVersion']));
}

/** @return array<string, mixed> */
function tripBody(LogSheet $sheet, array $extra = []): array
{
    return ['id' => (string) Str::uuid7(), 'sheet_id' => $sheet->id, 'status' => 'recording', 'started_at' => '2026-11-20T07:00:00Z', ...$extra];
}

/** @return list<array<string, mixed>> */
function tripPoints(int $from, int $count, int $startSecond = 0): array
{
    $out = [];
    for ($i = 0; $i < $count; $i++) {
        $seq = $from + $i;
        $out[] = ['seq' => $seq, 'lat' => 4.85 + ($seq * 100) / 111_195, 'lng' => 31.58, 'accuracy_m' => 8, 'captured_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime('2026-11-20T07:00:00Z') + $startSecond + $i * 10)];
    }

    return $out;
}

beforeEach(function () {
    $this->travelTo('2026-11-20 09:00:00');
    $this->team = team();
    as_($this->team['manager'], 'logbook_manager');
    $this->logbook = logbook('Transport');
    $GLOBALS['gpsLogbook'] = $this->logbook->id;
    $this->post(p('resources'), ['code' => 'KDB-123', 'name' => 'Land Cruiser', 'type' => 'Vehicle'])->assertSessionHasNoErrors();
    $this->vehicle = inTenant(fn () => LogResource::query()->where('code', 'KDB-123')->firstOrFail());
    $this->trips = locatedSheet('VML', ['mode' => 'route', 'required' => true, 'resource_required' => true, 'accuracy_m' => 30]);
});

// ---- Sheet settings ----

it('takes no location by default, and drops one sent to a sheet without GPS', function () {
    $plain = publishedSheet($this->logbook, 'GEN', review: false);
    expect(inTenant(fn () => $plain->publishedVersion->location))->toBeNull();

    onGpsPhone($this->team['writer'], 'logbook_contributor');
    $this->getJson(gps('kit'))->assertOk()->assertJsonPath('sheets.0.location', null);
    $this->postJson(gps('entries'), [
        'id' => (string) Str::uuid7(), 'sheet_id' => $plain->id, 'version_id' => $plain->published_version_id, 'occurred_at' => '2026-11-20T08:00:00Z',
        'values' => generatorValues(), 'submit' => true, 'location' => ['lat' => 4.85, 'lng' => 31.58, 'accuracy_m' => 5, 'captured_at' => '2026-11-20T08:00:00Z'],
    ])->assertCreated();
    expect(latestEntry()->location)->toBeNull();

    // And a plain sheet makes no route.
    $this->postJson(gps('tracks'), tripBody($plain))->assertStatus(409)->assertJsonPath('code', 'routes_off');
});

it('keeps location settings in the version, so publishing a change leaves earlier entries as they were', function () {
    $sheet = locatedSheet('INS', ['mode' => 'entry', 'required' => true]);
    $first = inTenant(fn () => $sheet->publishedVersion);
    $this->post(p("sheets/{$sheet->id}/publish"), ['fields' => generatorFields(), 'location' => ['mode' => 'off']])->assertSessionHasNoErrors();

    expect(inTenant(fn () => LogSheetVersion::query()->find($first->id)->location['mode']))->toBe('entry')
        ->and(inTenant(fn () => $sheet->fresh()->publishedVersion->location))->toBeNull();
    $this->put(p("sheets/{$sheet->id}/draft"), ['fields' => generatorFields(), 'location' => ['mode' => 'teleport']])->assertSessionHasErrors('location.mode');
});

// ---- Entry location ----

it('keeps the location taken for an entry, and refuses to submit without one when it is required', function () {
    $sheet = locatedSheet('INS', ['mode' => 'entry', 'required' => true]);
    onGpsPhone($this->team['writer'], 'logbook_contributor');
    $body = ['sheet_id' => $sheet->id, 'version_id' => $sheet->published_version_id, 'occurred_at' => '2026-11-20T08:00:00Z', 'values' => generatorValues(), 'submit' => true];

    // Location turned off on the phone: no coordinates are made up, the entry is refused.
    $this->postJson(gps('entries'), ['id' => (string) Str::uuid7(), ...$body])->assertStatus(422)->assertJsonValidationErrors('location');

    $this->postJson(gps('entries'), ['id' => (string) Str::uuid7(), ...$body, 'location' => ['lat' => 4.8517, 'lng' => 31.5825, 'accuracy_m' => 12.5, 'captured_at' => '2026-11-20T07:59:30Z']])->assertCreated();
    $entry = latestEntry();
    expect($entry->location)->toMatchArray(['lat' => 4.8517, 'lng' => 31.5825, 'accuracy_m' => 12.5, 'source' => 'phone', 'captured_at' => '2026-11-20T07:59:30+00:00'])
        ->and($entry->location['received_at'])->toBe('2026-11-20T09:00:00+00:00')
        ->and(inTenant(fn () => $entry->revisions()->sole()->location['lat']))->toBe(4.8517);
});

it('takes a browser location on the web form', function () {
    $sheet = locatedSheet('INS', ['mode' => 'entry', 'required' => false]);
    as_($this->team['writer'], 'logbook_contributor');
    $this->post(p('entries'), [
        'sheet_id' => $sheet->id, 'occurred_at' => '2026-11-20T08:00', 'values' => generatorValues(), 'submit' => true, 'idempotency_key' => (string) Str::uuid7(),
        'location' => ['lat' => 4.85, 'lng' => 31.58, 'accuracy_m' => 40, 'captured_at' => '2026-11-20T08:00:00Z'],
    ])->assertSessionHasNoErrors();
    expect(latestEntry()->location['source'])->toBe('browser');
});

// ---- Routes from the phone ----

it('starts, pauses, resumes and ends a route, once, however often the phone repeats itself', function () {
    onGpsPhone($this->team['writer'], 'logbook_contributor');
    $body = tripBody($this->trips, ['resource_id' => $this->vehicle->id]);

    $this->postJson(gps('tracks'), $body)->assertOk()->assertJsonPath('status', 'recording');
    $this->postJson(gps('tracks'), $body)->assertOk(); // the reply was lost: sent again
    $this->postJson(gps('tracks'), [...$body, 'status' => 'paused'])->assertOk()->assertJsonPath('status', 'paused');
    $this->postJson(gps('tracks'), [...$body, 'status' => 'recording'])->assertOk()->assertJsonPath('status', 'recording');
    $this->postJson(gps('tracks'), [...$body, 'status' => 'ended', 'ended_at' => '2026-11-20T08:30:00Z'])->assertOk()->assertJsonPath('status', 'ended');
    // Ended is final: a late "recording" from a confused phone changes nothing.
    $this->postJson(gps('tracks'), [...$body, 'status' => 'recording'])->assertOk()->assertJsonPath('status', 'ended');

    expect(inTenant(fn () => Track::query()->count()))->toBe(1)
        ->and(inTenant(fn () => AuditEvent::query()->where('subject_type', 'track')->pluck('action')->sort()->values()->all()))->toBe(['track.ended', 'track.started']);
});

it('needs the vehicle when the sheet says so, and only one that exists', function () {
    onGpsPhone($this->team['writer'], 'logbook_contributor');
    $this->postJson(gps('tracks'), tripBody($this->trips))->assertStatus(409)->assertJsonPath('code', 'resource_required');
    $this->postJson(gps('tracks'), tripBody($this->trips, ['resource_id' => (string) Str::uuid7()]))->assertStatus(409)->assertJsonPath('code', 'resource_unknown');
});

it('stores points in batches, ignores those sent twice, and resumes after a lost reply', function () {
    onGpsPhone($this->team['writer'], 'logbook_contributor');
    $body = tripBody($this->trips, ['resource_id' => $this->vehicle->id]);
    $this->postJson(gps('tracks'), $body)->assertOk();

    $this->postJson(gps("tracks/{$body['id']}/points"), ['points' => tripPoints(1, 10), 'events' => [['seq' => 11, 'kind' => 'pause', 'at' => '2026-11-20T07:01:45Z']]])
        ->assertOk()->assertJsonPath('acknowledged', 11);
    // The reply was lost; the phone sends the same batch and the next one together.
    $this->postJson(gps("tracks/{$body['id']}/points"), ['points' => [...tripPoints(1, 10), ...tripPoints(13, 5, 300)], 'events' => [['seq' => 12, 'kind' => 'resume', 'at' => '2026-11-20T07:04:55Z']]])
        ->assertOk()->assertJsonPath('acknowledged', 17)->assertJsonPath('track.points', 15);

    $track = inTenant(fn () => Track::query()->findOrFail($body['id']));
    // 9 steps of 100 m before the pause, 4 after; nothing across the pause.
    expect($track->distance_m)->toBeBetween(1290, 1310)
        ->and($track->gaps_count)->toBe(0)
        ->and($track->last_seq)->toBe(17)
        ->and(inTenant(fn () => TrackPoint::query()->where('log_track_id', $track->id)->count()))->toBe(15);
});

it('refuses impossible points but keeps the rest, and does not believe a clock far ahead', function () {
    onGpsPhone($this->team['writer'], 'logbook_contributor');
    $body = tripBody($this->trips, ['resource_id' => $this->vehicle->id]);
    $this->postJson(gps('tracks'), $body)->assertOk();
    $points = tripPoints(1, 3);
    $points[] = ['seq' => 4, 'lat' => 123, 'lng' => 31.58, 'captured_at' => '2026-11-20T07:01:00Z'];
    $points[] = ['seq' => 5, 'lat' => 4.86, 'lng' => 31.58, 'captured_at' => '2026-11-21T07:01:00Z'];

    $this->postJson(gps("tracks/{$body['id']}/points"), ['points' => $points, 'events' => []])
        ->assertOk()->assertJsonPath('acknowledged', 5)->assertJsonPath('refused', [4, 5])->assertJsonPath('track.points', 3);
});

it('keeps one person\'s route id from being used by another', function () {
    onGpsPhone($this->team['writer'], 'logbook_contributor');
    $body = tripBody($this->trips, ['resource_id' => $this->vehicle->id]);
    $this->postJson(gps('tracks'), $body)->assertOk();

    onGpsPhone($this->team['other'], 'logbook_contributor');
    $this->postJson(gps('tracks'), $body)->assertStatus(409)->assertJsonPath('code', 'track_taken');
    $this->postJson(gps("tracks/{$body['id']}/points"), ['points' => tripPoints(1, 2), 'events' => []])->assertStatus(409)->assertJsonPath('code', 'track_unknown');
});

it('links entries to the person\'s own route only', function () {
    onGpsPhone($this->team['writer'], 'logbook_contributor');
    $trip = tripBody($this->trips, ['resource_id' => $this->vehicle->id]);
    $this->postJson(gps('tracks'), $trip)->assertOk();
    $entry = fn (array $extra) => ['id' => (string) Str::uuid7(), 'sheet_id' => $this->trips->id, 'version_id' => $this->trips->published_version_id,
        'occurred_at' => '2026-11-20T07:30:00Z', 'values' => generatorValues(), 'submit' => true, ...$extra];

    $this->postJson(gps('entries'), $entry(['track_id' => $trip['id']]))->assertCreated()->assertJsonPath('trackId', $trip['id']);
    $this->postJson(gps('entries'), $entry(['track_id' => $trip['id'], 'location' => ['lat' => 4.86, 'lng' => 31.58, 'captured_at' => '2026-11-20T07:40:00Z']]))->assertCreated();

    onGpsPhone($this->team['other'], 'logbook_contributor');
    // Someone else naming the writer's route: the entry is kept, the link is not (and, required, it needs its own location).
    $this->postJson(gps('entries'), $entry(['track_id' => $trip['id'], 'location' => ['lat' => 4.86, 'lng' => 31.58, 'captured_at' => '2026-11-20T07:45:00Z']]))
        ->assertCreated()->assertJsonPath('trackId', null);

    expect(inTenant(fn () => Entry::query()->where('log_track_id', $trip['id'])->count()))->toBe(2);
});

it('stops taking routes from someone who lost their role or the sheet', function () {
    onGpsPhone($this->team['viewer'], 'logbook_viewer');
    $this->postJson(gps('tracks'), tripBody($this->trips, ['resource_id' => $this->vehicle->id]))->assertForbidden();

    as_($this->team['manager'], 'logbook_manager');
    $this->post(p("sheets/{$this->trips->id}/archive"))->assertSessionHasNoErrors();
    onGpsPhone($this->team['writer'], 'logbook_contributor');
    $this->postJson(gps('tracks'), tripBody($this->trips, ['resource_id' => $this->vehicle->id]))->assertStatus(409)->assertJsonPath('code', 'sheet_closed');
});

// ---- Seeing routes ----

it('shows people their own routes, and others\' only with the permission and the logbook', function () {
    onGpsPhone($this->team['writer'], 'logbook_contributor');
    $trip = tripBody($this->trips, ['resource_id' => $this->vehicle->id]);
    $this->postJson(gps('tracks'), $trip)->assertOk();
    $this->postJson(gps("tracks/{$trip['id']}/points"), ['points' => tripPoints(1, 5), 'events' => []])->assertOk();

    as_($this->team['writer'], 'logbook_contributor');
    $this->get(p('routes'))->assertInertia(fn ($page) => $page->component('tracks/index')->has('tracks.data', 1)->where('seesOthers', false)
        // The newest trip is drawn beside the list, with its route.
        ->where('selected.id', $trip['id'])->has('selected.route.segments.0', 5));
    $this->get(p("routes/{$trip['id']}"))->assertInertia(fn ($page) => $page->component('tracks/show')
        ->where('track.resource.label', 'KDB-123 · Land Cruiser')->has('route.segments.0', 5));

    // A colleague and a reviewer without the permission: nothing, not even the page.
    as_($this->team['other'], 'logbook_contributor');
    $this->get(p('routes'))->assertInertia(fn ($page) => $page->has('tracks.data', 0));
    $this->get(p("routes/{$trip['id']}"))->assertNotFound();
    // Naming the trip in the list's address shows nothing either.
    $this->get(p("routes?track={$trip['id']}"))->assertInertia(fn ($page) => $page->has('tracks.data', 0)->where('selected', null));
    as_($this->team['reviewer'], 'logbook_reviewer');
    $this->get(p("routes/{$trip['id']}"))->assertNotFound();

    // An auditor holds logbook.tracks.view: sees it, and exporting it is audited.
    as_($this->team['auditor'], 'logbook_auditor');
    $this->get(p('routes'))->assertInertia(fn ($page) => $page->has('tracks.data', 1)->where('seesOthers', true));
    $this->get(p("routes/{$trip['id']}/gpx"))->assertOk()->assertHeader('content-type', 'application/gpx+xml');
    expect(inTenant(fn () => AuditEvent::query()->where('action', 'track.exported')->count()))->toBe(1);

    // Off the logbook, the auditor no longer sees it.
    removeMember($this->logbook, $this->team['auditor']);
    as_($this->team['auditor'], 'logbook_auditor');
    $this->get(p("routes/{$trip['id']}"))->assertNotFound();
    // The writer still sees their own.
    as_($this->team['writer'], 'logbook_contributor');
    $this->get(p("routes/{$trip['id']}"))->assertOk();
});

it('keeps another workspace\'s routes out of reach', function () {
    onGpsPhone($this->team['writer'], 'logbook_contributor');
    $trip = tripBody($this->trips, ['resource_id' => $this->vehicle->id]);
    $this->postJson(gps('tracks'), $trip)->assertOk();

    $stranger = actingAsRole('logbook_administrator', slug: 'globex', name: 'Stranger');
    as_($stranger, 'logbook_administrator', 'globex');
    $this->get(p("routes/{$trip['id']}", 'globex'))->assertNotFound();
    onGpsPhone($stranger, 'logbook_administrator', 'globex');
    $this->postJson(gps("tracks/{$trip['id']}/points", 'globex'), ['points' => tripPoints(1, 2), 'events' => []])->assertStatus(409);
    $this->postJson(gps('tracks', 'globex'), $trip)->assertStatus(409)->assertJsonPath('code', 'track_taken');
});

it('lets a manager remove a route; entries keep their own location', function () {
    onGpsPhone($this->team['writer'], 'logbook_contributor');
    $trip = tripBody($this->trips, ['resource_id' => $this->vehicle->id]);
    $this->postJson(gps('tracks'), $trip)->assertOk();
    $this->postJson(gps("tracks/{$trip['id']}/points"), ['points' => tripPoints(1, 3), 'events' => []])->assertOk();
    $this->postJson(gps('entries'), ['id' => (string) Str::uuid7(), 'sheet_id' => $this->trips->id, 'version_id' => $this->trips->published_version_id,
        'occurred_at' => '2026-11-20T07:30:00Z', 'values' => generatorValues(), 'submit' => true, 'track_id' => $trip['id'],
        'location' => ['lat' => 4.86, 'lng' => 31.58, 'captured_at' => '2026-11-20T07:30:00Z']])->assertCreated();

    as_($this->team['writer'], 'logbook_contributor');
    $this->delete(p("routes/{$trip['id']}"))->assertForbidden();
    as_($this->team['manager'], 'logbook_manager');
    $this->delete(p("routes/{$trip['id']}"))->assertRedirect();

    expect(inTenant(fn () => Track::query()->count()))->toBe(0)
        ->and(inTenant(fn () => TrackPoint::query()->count()))->toBe(0)
        ->and(latestEntry()->log_track_id)->toBeNull()
        ->and(latestEntry()->location['lat'])->toBe(4.86)
        ->and(inTenant(fn () => AuditEvent::query()->where('action', 'track.deleted')->count()))->toBe(1);
});

it('removes routes past their retention, and only those', function () {
    config(['logbook.tracks.retention_days' => 30]);
    onGpsPhone($this->team['writer'], 'logbook_contributor');
    $old = tripBody($this->trips, ['resource_id' => $this->vehicle->id, 'started_at' => '2026-09-01T07:00:00Z']);
    $this->postJson(gps('tracks'), $old)->assertOk();
    $this->postJson(gps('tracks'), [...$old, 'status' => 'ended', 'ended_at' => '2026-09-01T09:00:00Z'])->assertOk();
    $recent = tripBody($this->trips, ['resource_id' => $this->vehicle->id]);
    $this->postJson(gps('tracks'), $recent)->assertOk();

    $this->artisan('logbook:operations')->assertSuccessful();

    expect(inTenant(fn () => Track::query()->pluck('id')->all()))->toBe([$recent['id']])
        ->and(inTenant(fn () => AuditEvent::query()->where('action', 'track.expired')->count()))->toBe(1);
});

// ---- Coming soon ----

it('shows the GPS devices and fleet pages as coming soon, with nothing behind them', function () {
    as_($this->team['writer'], 'logbook_contributor');
    $this->get(p('coming-soon/gps-devices'))->assertInertia(fn ($page) => $page->component('integrations/coming-soon')->where('page', 'gps-devices'));
    $this->get(p('coming-soon/fleet'))->assertInertia(fn ($page) => $page->where('page', 'fleet'));
    $this->get(p('coming-soon/traccar'))->assertNotFound();
    // Only phones record: no other source is accepted, and no device API exists.
    expect(Track::SOURCES)->toBe(['phone']);
    $this->postJson(gps('devices'))->assertNotFound();
});
