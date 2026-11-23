<?php

use App\Logbook\Models\Entry;
use App\Logbook\Models\EntryRevision;
use App\Logbook\Models\LogResource;
use App\Logbook\Models\LogSheet;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/*
 * ADR-0030 phase 5: the activity on the entry, the fuel estimate kept at
 * submission, and the meter checks. Two fixed formulas; the refill is
 * never the fuel used; a difference is never a verdict.
 */

beforeEach(function () {
    $this->travelTo('2026-11-20 18:00:00');
    $this->team = team();
    as_($this->team['manager'], 'logbook_manager');
    $this->logbook = logbook('Fleet and plant');
    foreach ([['VEH-01', 'Land Cruiser', 'Vehicle'], ['GEN-01', 'Main generator', 'Generator'], ['BOAT-1', 'River boat', 'Boat']] as [$code, $name, $type]) {
        $this->post(p('resources'), ['code' => $code, 'name' => $name, 'type' => $type])->assertSessionHasNoErrors();
    }
    $this->vehicle = inTenant(fn () => LogResource::query()->where('code', 'VEH-01')->sole());
    $this->generator = inTenant(fn () => LogResource::query()->where('code', 'GEN-01')->sole());
    $this->boat = inTenant(fn () => LogResource::query()->where('code', 'BOAT-1')->sole());
    $this->post(p("resources/{$this->vehicle->id}/fuel-rates"), ['fuel_type' => 'diesel', 'method' => 'distance', 'rate' => '12', 'effective_from' => '2026-01-01T00:00'])->assertSessionHasNoErrors();
    $this->post(p("resources/{$this->generator->id}/fuel-rates"), ['fuel_type' => 'diesel', 'method' => 'hours', 'rate' => '4.5', 'effective_from' => '2026-01-01T00:00'])->assertSessionHasNoErrors();
    $this->post(p("resources/{$this->boat->id}/fuel-rates"), ['fuel_type' => 'petrol', 'method' => 'hours', 'rate' => '20', 'effective_from' => '2026-01-01T00:00'])->assertSessionHasNoErrors();
});

function meterFields(string $resourceType): array
{
    return [
        ['name' => 'unit', 'label' => $resourceType, 'type' => 'resource', 'required' => true, 'resource_type' => $resourceType],
        ['name' => 'start', 'label' => 'Start reading', 'type' => 'decimal', 'required' => false, 'min' => '0'],
        ['name' => 'end', 'label' => 'End reading', 'type' => 'decimal', 'required' => false, 'min' => '0'],
        ['name' => 'used', 'label' => 'Fuel used (L)', 'type' => 'decimal', 'required' => false, 'min' => '0'],
        ['name' => 'refill', 'label' => 'Refill (L)', 'type' => 'decimal', 'required' => false, 'min' => '0'],
        ['name' => 'notes', 'label' => 'Notes', 'type' => 'text', 'required' => false, 'max_length' => 200],
    ];
}

function activitySheet(string $code, string $mode, string $measure, string $resourceType, array $override = []): LogSheet
{
    test()->post(p('logbooks/'.test()->logbook->id.'/sheets'), ['name' => "{$code} log", 'code' => $code, 'review_required' => false])->assertSessionHasNoErrors();
    $sheet = inTenant(fn () => LogSheet::query()->where('code', $code)->firstOrFail());
    test()->post(p("sheets/{$sheet->id}/publish"), ['fields' => meterFields($resourceType), 'recording' => ['mode' => $mode, 'activity' => [
        'resource_field' => 'unit', 'measure' => $measure,
        'start_field' => $measure === 'duration' ? null : 'start', 'end_field' => $measure === 'duration' ? null : 'end',
        'actual_field' => 'used', 'refill_field' => 'refill', ...$override,
    ]]])->assertSessionHasNoErrors();

    return inTenant(fn () => $sheet->fresh());
}

function trip(LogSheet $sheet, string $resourceId, array $values, array $extra = [])
{
    return test()->post(p('entries'), [
        'sheet_id' => $sheet->id, 'occurred_at' => '2026-11-20T08:00', 'activity_ended_at' => '2026-11-20T10:00',
        'values' => ['unit' => $resourceId, ...$values], 'idempotency_key' => (string) Str::uuid7(), 'submit' => true, ...$extra,
    ]);
}

it('estimates fuel by distance and keeps the figure on the entry and its revision', function () {
    $sheet = activitySheet('TRIP', 'event', 'odometer', 'Vehicle');
    as_($this->team['writer'], 'logbook_contributor');
    trip($sheet, $this->vehicle->id, ['start' => '10000', 'end' => '10250', 'used' => '33', 'refill' => '60'])->assertSessionHasNoErrors();
    $entry = latestEntry();
    $c = $entry->calculation;

    expect($c['method'])->toBe('distance')->and($c['quantity'])->toEqual(250)->and($c['unit'])->toBe('km')
        ->and($c['rate']['value'])->toEqual(12)->and($c['estimated_l'])->toEqual(30)
        ->and($c['actual_l'])->toEqual(33)->and($c['variance_l'])->toEqual(3)->and($c['variance_pct'])->toEqual(10)
        // The refill is shown, never used as the actual figure.
        ->and($c['refill_l'])->toEqual(60)->and($c['flags'])->toBe([])->and($c['by'])->toBe('server');
    expect([$entry->meter_measure, $entry->meter_start, $entry->meter_end])->toBe(['odometer', '10000.0', '10250.0']);
    $revision = inTenant(fn () => EntryRevision::query()->where('log_entry_id', $entry->id)->sole());
    expect($revision->calculation['estimated_l'])->toEqual(30)->and($revision->activity_ended_at?->toIso8601String())->toBe($entry->activity_ended_at?->toIso8601String());

    $this->get(p("entries/{$entry->id}"))->assertInertia(fn ($page) => $page
        ->where('entry.calculation.estimated_l', 30)
        ->where('entry.calculation.resource', 'VEH-01 · Land Cruiser'));
});

it('estimates by running hours from an hour meter, or from the start and end times', function () {
    $meter = activitySheet('GENR', 'on_demand', 'hour_meter', 'Generator');
    $timed = activitySheet('BOAT', 'event', 'duration', 'Boat');
    as_($this->team['writer'], 'logbook_contributor');

    trip($meter, $this->generator->id, ['start' => '1200.5', 'end' => '1206.5'])->assertSessionHasNoErrors();
    expect(latestEntry()->calculation)->toMatchArray(['method' => 'hours', 'quantity' => 6.0, 'estimated_l' => 27.0, 'actual_l' => null, 'variance_l' => null]);

    // 08:00 to 10:30 at 20 L/h.
    trip($timed, $this->boat->id, [], ['activity_ended_at' => '2026-11-20T10:30'])->assertSessionHasNoErrors();
    expect(latestEntry()->calculation)->toMatchArray(['quantity_source' => 'recorded_duration', 'quantity' => 2.5, 'estimated_l' => 50.0])
        ->and(latestEntry()->meter_measure)->toBeNull();
});

it('uses the rate in force when the activity started, and never recalculates afterwards', function () {
    $sheet = activitySheet('TRIP', 'event', 'odometer', 'Vehicle');
    as_($this->team['manager'], 'logbook_manager');
    $this->post(p("resources/{$this->vehicle->id}/fuel-rates"), ['fuel_type' => 'diesel', 'method' => 'distance', 'rate' => '15', 'effective_from' => '2026-11-20T09:00'])->assertSessionHasNoErrors();

    as_($this->team['writer'], 'logbook_contributor');
    // Started 08:00, before the new rate: 100 km × 12 ÷ 100.
    trip($sheet, $this->vehicle->id, ['start' => '100', 'end' => '200'])->assertSessionHasNoErrors();
    $before = latestEntry();
    expect($before->calculation['estimated_l'])->toEqual(12);
    trip($sheet, $this->vehicle->id, ['start' => '200', 'end' => '300'], ['occurred_at' => '2026-11-20T11:00', 'activity_ended_at' => '2026-11-20T12:00'])->assertSessionHasNoErrors();
    expect(latestEntry()->calculation['estimated_l'])->toEqual(15);

    // A correction to the old rate later on leaves recorded entries as they were.
    as_($this->team['manager'], 'logbook_manager');
    $this->post(p("resources/{$this->vehicle->id}/fuel-rates"), ['fuel_type' => 'diesel', 'method' => 'distance', 'rate' => '13', 'effective_from' => '2026-01-01T00:00'])->assertSessionHasNoErrors();
    expect(inTenant(fn () => $before->fresh()->calculation['estimated_l']))->toEqual(12);
});

it('records the activity without an estimate when the resource has no rate, or a rate for another measure', function () {
    $sheet = activitySheet('GENR', 'on_demand', 'hour_meter', 'Vehicle');
    as_($this->team['writer'], 'logbook_contributor');
    // The vehicle is rated by distance; this sheet reads an hour meter.
    trip($sheet, $this->vehicle->id, ['start' => '1', 'end' => '3'])->assertSessionHasNoErrors();
    expect(latestEntry()->calculation)->toMatchArray(['estimated_l' => null, 'flags' => ['rate_method_mismatch']]);

    as_($this->team['manager'], 'logbook_manager');
    $this->post(p('resources'), ['code' => 'VEH-02', 'name' => 'Pickup', 'type' => 'Vehicle'])->assertSessionHasNoErrors();
    $unrated = inTenant(fn () => LogResource::query()->where('code', 'VEH-02')->sole());
    as_($this->team['writer'], 'logbook_contributor');
    trip($sheet, $unrated->id, ['start' => '1', 'end' => '3'])->assertSessionHasNoErrors();
    expect(latestEntry()->calculation)->toMatchArray(['estimated_l' => null, 'rate' => null, 'flags' => ['no_rate']]);
});

it('refuses readings that run backwards, and accepts them after a recorded meter replacement', function () {
    $sheet = activitySheet('TRIP', 'event', 'odometer', 'Vehicle');
    as_($this->team['writer'], 'logbook_contributor');
    trip($sheet, $this->vehicle->id, ['start' => '5000', 'end' => '5100'])->assertSessionHasNoErrors();

    trip($sheet, $this->vehicle->id, ['start' => '5200', 'end' => '5150'], ['occurred_at' => '2026-11-20T11:00', 'activity_ended_at' => '2026-11-20T12:00'])->assertSessionHasErrors('values.end');
    trip($sheet, $this->vehicle->id, ['start' => '4000', 'end' => '4050'], ['occurred_at' => '2026-11-20T11:00', 'activity_ended_at' => '2026-11-20T12:00'])
        ->assertSessionHasErrors(['values.start' => 'The start reading 4000 is below the last end reading 5100 ('.latestEntry()->reference.', 2026-11-20). If the odometer was replaced or reset, a manager records it on the resource first.']);
    expect(inTenant(fn () => Entry::query()->count()))->toBe(1);

    // The meter was replaced at 10:30 and now starts from 0.
    as_($this->team['manager'], 'logbook_manager');
    $this->post(p("resources/{$this->vehicle->id}/meter-events"), ['measure' => 'odometer', 'kind' => 'replaced', 'reading' => '0', 'occurred_at' => '2026-11-20T10:30', 'reason' => 'Cluster replaced'])->assertSessionHasNoErrors();
    as_($this->team['writer'], 'logbook_contributor');
    trip($sheet, $this->vehicle->id, ['start' => '0', 'end' => '40'], ['occurred_at' => '2026-11-20T11:00', 'activity_ended_at' => '2026-11-20T12:00'])->assertSessionHasNoErrors();
    expect(latestEntry()->calculation['flags'])->toBe([]);
    // But not below what the new meter showed when fitted.
    trip($sheet, $this->vehicle->id, ['start' => '30', 'end' => '60'], ['occurred_at' => '2026-11-20T13:00', 'activity_ended_at' => '2026-11-20T14:00'])->assertSessionHasErrors('values.start');
});

it('flags activities whose readings overlap instead of refusing or adding them twice', function () {
    $sheet = activitySheet('TRIP', 'event', 'odometer', 'Vehicle');
    as_($this->team['writer'], 'logbook_contributor');
    trip($sheet, $this->vehicle->id, ['start' => '100', 'end' => '200'], ['occurred_at' => '2026-11-20T12:00', 'activity_ended_at' => '2026-11-20T13:00'])->assertSessionHasNoErrors();
    $later = latestEntry();
    // Recorded afterwards for an earlier trip, with readings into the later one.
    trip($sheet, $this->vehicle->id, ['start' => '50', 'end' => '150'])->assertSessionHasNoErrors();
    expect(latestEntry()->calculation['flags'])->toBe(['overlap'])
        ->and(latestEntry()->calculation['overlaps'])->toBe([['id' => $later->id, 'reference' => $later->reference]]);
});

it('keeps an event in progress as a draft until it ends', function () {
    $sheet = activitySheet('TRIP', 'event', 'odometer', 'Vehicle');
    as_($this->team['writer'], 'logbook_contributor');
    trip($sheet, $this->vehicle->id, ['start' => '100'], ['activity_started_at' => '2026-11-20T08:00', 'activity_ended_at' => null])->assertSessionHasErrors('activity_ended_at');
    trip($sheet, $this->vehicle->id, ['start' => '100'], ['activity_started_at' => '2026-11-20T08:00', 'activity_ended_at' => null, 'submit' => false])->assertSessionHasNoErrors();
    $draft = latestEntry();
    expect($draft->status)->toBe('draft')->and($draft->activity_started_at)->not->toBeNull()->and($draft->calculation)->toBeNull();

    // Ended later; the end before the start is refused.
    $this->put(p("entries/{$draft->id}"), ['occurred_at' => '2026-11-20T08:00', 'activity_ended_at' => '2026-11-20T07:00', 'values' => ['unit' => $this->vehicle->id, 'start' => '100', 'end' => '180'], 'submit' => true])->assertSessionHasErrors('activity_ended_at');
    $this->put(p("entries/{$draft->id}"), ['occurred_at' => '2026-11-20T08:00', 'activity_started_at' => '2026-11-20T08:00', 'activity_ended_at' => '2026-11-20T09:30', 'values' => ['unit' => $this->vehicle->id, 'start' => '100', 'end' => '180'], 'submit' => true])->assertSessionHasNoErrors();
    expect(inTenant(fn () => $draft->fresh()->calculation['estimated_l']))->toEqual(9.6);
});

it('links an observation to the activity it was made during', function () {
    $sheet = activitySheet('TRIP', 'event', 'odometer', 'Vehicle');
    as_($this->team['writer'], 'logbook_contributor');
    trip($sheet, $this->vehicle->id, ['start' => '100', 'end' => '200'])->assertSessionHasNoErrors();
    $tripEntry = latestEntry();
    as_($this->team['manager'], 'logbook_manager');
    $notes = publishedSheet($this->logbook, 'OBS', review: false);
    $other = logbook('Elsewhere');
    as_($this->team['writer'], 'logbook_contributor');

    $this->get(p("entries/new?sheet={$notes->id}&activity={$tripEntry->id}"))->assertInertia(fn ($page) => $page->where('activityEntry.id', $tripEntry->id));
    writeEntry($notes, ['generator' => 'Flat tyre', 'hours' => '0', 'condition' => 'faulty'])->assertSessionHasNoErrors();
    $this->post(p('entries'), ['sheet_id' => $notes->id, 'occurred_at' => '2026-11-20T09:00', 'values' => generatorValues(), 'idempotency_key' => (string) Str::uuid7(), 'submit' => true, 'activity_entry_id' => $tripEntry->id])->assertSessionHasNoErrors();
    $observation = latestEntry();
    expect($observation->activity_entry_id)->toBe($tripEntry->id);
    $this->get(p("entries/{$tripEntry->id}"))->assertInertia(fn ($page) => $page->where('entry.related.0.id', $observation->id));

    // Not to an entry of another logbook.
    expect($other->id)->not->toBe($this->logbook->id);
    $this->post(p('entries'), ['sheet_id' => $notes->id, 'occurred_at' => '2026-11-20T09:00', 'values' => generatorValues(), 'idempotency_key' => (string) Str::uuid7(), 'submit' => true, 'activity_entry_id' => (string) Str::uuid7()])->assertSessionHasErrors('activity_entry_id');
});

it('refuses activity settings that do not match the template', function () {
    $this->post(p('logbooks/'.$this->logbook->id.'/sheets'), ['name' => 'Bad', 'code' => 'BAD', 'review_required' => false])->assertSessionHasNoErrors();
    $sheet = inTenant(fn () => LogSheet::query()->where('code', 'BAD')->sole());
    $publish = fn (array $activity, string $mode = 'event') => $this->post(p("sheets/{$sheet->id}/publish"), ['fields' => meterFields('Vehicle'), 'recording' => ['mode' => $mode, 'activity' => [
        'resource_field' => 'unit', 'measure' => 'odometer', 'start_field' => 'start', 'end_field' => 'end', 'actual_field' => null, 'refill_field' => null, ...$activity,
    ]]]);
    $publish(['resource_field' => 'notes'])->assertSessionHasErrors('recording.activity');
    $publish(['start_field' => 'notes'])->assertSessionHasErrors('recording.activity');
    $publish(['end_field' => 'start'])->assertSessionHasErrors('recording.activity');
    $publish(['actual_field' => 'end'])->assertSessionHasErrors('recording.activity');
    $publish(['measure' => 'duration'], 'on_demand')->assertSessionHasErrors('recording.activity');
    $publish(['measure' => 'distance_by_gps'])->assertSessionHasErrors('recording.activity');
    $publish([])->assertSessionHasNoErrors();
});

it('keeps the phone estimate as evidence and flags a different rate', function () {
    $sheet = activitySheet('TRIP', 'event', 'odometer', 'Vehicle');
    $this->actingAsAppMember('acme', rolePermissions('logbook_contributor'), user: $this->team['writer']);
    Cache::flush();
    $url = fn (string $path) => $this->workspaceUrl('acme', 'api/v1/'.$path);

    $kit = $this->getJson($url('kit'))->assertOk();
    $vehicle = collect($kit->json('resources'))->firstWhere('id', $this->vehicle->id);
    expect($vehicle['fuel'][0]['value'])->toEqual(12)->and(collect($kit->json('sheets'))->firstWhere('id', $sheet->id)['recording']['activity']['measure'])->toBe('odometer');

    $this->postJson($url('entries'), [
        'id' => (string) Str::uuid7(), 'sheet_id' => $sheet->id, 'version_id' => $sheet->published_version_id, 'submit' => true,
        'occurred_at' => '2026-11-20T08:00:00Z', 'activity_started_at' => '2026-11-20T08:00:00Z', 'activity_ended_at' => '2026-11-20T09:00:00Z',
        'values' => ['unit' => $this->vehicle->id, 'start' => '700', 'end' => '800'],
        'calculation' => ['estimated_l' => 14, 'rate_id' => (string) Str::uuid7(), 'rate' => 14, 'quantity' => 100],
    ])->assertSuccessful()
        // What the phone keeps when it corrects the entry later.
        ->assertJsonPath('activityStartedAt', fn ($t) => str_starts_with((string) $t, '2026-11-20'))->assertJsonPath('activityEndedAt', fn ($t) => $t !== null)->assertJsonPath('shiftId', null);
    $c = latestEntry()->calculation;
    expect($c['estimated_l'])->toEqual(12)->and($c['phone']['estimated_l'])->toEqual(14)->and($c['flags'])->toBe(['rate_changed']);

    // The kit now knows the last odometer reading.
    expect(collect($this->getJson($url('kit'))->json('resources'))->firstWhere('id', $this->vehicle->id)['meters'])->toEqual(['odometer' => 800]);
});

it('leaves sheets without an activity exactly as before', function () {
    $sheet = publishedSheet($this->logbook, 'GEN', review: false);
    as_($this->team['writer'], 'logbook_contributor');
    writeEntry($sheet)->assertSessionHasNoErrors();
    $entry = latestEntry();
    expect($entry->calculation)->toBeNull()->and($entry->meter_resource_id)->toBeNull()->and($entry->activity_started_at)->toBeNull();
});
