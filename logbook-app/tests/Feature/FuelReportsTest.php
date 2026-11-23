<?php

use App\Logbook\Models\LogResource;
use App\Logbook\Models\LogSheet;
use App\Logbook\Models\Schedule;
use App\Logbook\Models\Shift;
use App\Models\User;
use Illuminate\Support\Str;

/*
 * ADR-0030 phase 6: reports by recording mode and shift, the activities
 * view with fuel totals built from each entry's kept figure, the record
 * panel and the activities export.
 */

beforeEach(function () {
    $this->travelTo('2026-11-20 18:00:00');
    $this->team = team();
    as_($this->team['manager'], 'logbook_manager');
    $this->logbook = logbook('Fleet');
    foreach ([['VEH-01', 'Land Cruiser'], ['VEH-02', 'Pickup']] as [$code, $name]) {
        $this->post(p('resources'), ['code' => $code, 'name' => $name, 'type' => 'Vehicle'])->assertSessionHasNoErrors();
    }
    $this->v1 = inTenant(fn () => LogResource::query()->where('code', 'VEH-01')->sole());
    $this->v2 = inTenant(fn () => LogResource::query()->where('code', 'VEH-02')->sole());
    foreach ([$this->v1, $this->v2] as $v) {
        $this->post(p("resources/{$v->id}/fuel-rates"), ['fuel_type' => 'diesel', 'method' => 'distance', 'rate' => '10', 'effective_from' => '2026-01-01T00:00'])->assertSessionHasNoErrors();
    }
    $this->post(p('shifts'), ['name' => 'Day', 'starts_time' => '06:00', 'ends_time' => '18:00'])->assertSessionHasNoErrors();
    $this->day = inTenant(fn () => Shift::query()->sole());

    $this->post(p("logbooks/{$this->logbook->id}/sheets"), ['name' => 'Trips', 'code' => 'TRIP', 'review_required' => true])->assertSessionHasNoErrors();
    $this->trips = inTenant(fn () => LogSheet::query()->where('code', 'TRIP')->sole());
    $this->post(p("sheets/{$this->trips->id}/publish"), ['fields' => [
        ['name' => 'unit', 'label' => 'Vehicle', 'type' => 'resource', 'required' => true],
        ['name' => 'start', 'label' => 'Odometer out', 'type' => 'decimal', 'required' => true, 'min' => '0'],
        ['name' => 'end', 'label' => 'Odometer in', 'type' => 'decimal', 'required' => true, 'min' => '0'],
        ['name' => 'used', 'label' => 'Fuel used', 'type' => 'decimal', 'required' => false, 'min' => '0'],
        ['name' => 'refill', 'label' => 'Refill', 'type' => 'decimal', 'required' => false, 'min' => '0'],
    ], 'recording' => ['mode' => 'event', 'shifts' => 'optional', 'activity' => [
        'resource_field' => 'unit', 'measure' => 'odometer', 'start_field' => 'start', 'end_field' => 'end', 'actual_field' => 'used', 'refill_field' => 'refill',
    ]]])->assertSessionHasNoErrors();
});

function recordTrip(LogResource $vehicle, string $start, string $end, array $more = [], string $at = '2026-11-20T08:00')
{
    test()->post(p('entries'), [
        'sheet_id' => test()->trips->id, 'occurred_at' => $at, 'activity_started_at' => $at,
        'activity_ended_at' => substr($at, 0, 11).'17:00', 'idempotency_key' => (string) Str::uuid7(), 'submit' => true,
        'values' => ['unit' => $vehicle->id, 'start' => $start, 'end' => $end, ...($more['values'] ?? [])],
        ...array_diff_key($more, ['values' => 1]),
    ])->assertSessionHasNoErrors();

    return latestEntry();
}

it('totals fuel per resource, operator, shift and month from each entry\'s own figure', function () {
    as_($this->team['writer'], 'logbook_contributor');
    // VEH-01: 100 km (est. 10 L, measured 12, refill 50) and 200 km (est. 20 L, nothing measured).
    recordTrip($this->v1, '1000', '1100', ['values' => ['used' => '12', 'refill' => '50'], 'shift_id' => $this->day->id]);
    recordTrip($this->v1, '1100', '1300', [], '2026-10-05T08:00');
    as_($this->team['other'], 'logbook_contributor');
    // VEH-02: 50 km (est. 5 L, measured 4).
    recordTrip($this->v2, '500', '550', ['values' => ['used' => '4']]);

    as_($this->team['manager'], 'logbook_manager');
    $summary = $this->get(p('reports?view=activities'))->assertOk()->viewData('page')['props']['activities']['summary'];

    expect($summary['count'])->toBe(3)
        ->and($summary['total'])->toMatchArray(['entries' => 3, 'km' => 350.0, 'estimated_l' => 35.0, 'actual_l' => 16.0, 'refill_l' => 50.0, 'compared' => 2])
        // The difference only over the two with both figures: (12 + 4) − (10 + 5).
        ->and($summary['total']['variance_l'])->toEqual(1.0)->and($summary['total']['variance_pct'])->toEqual(6.7);
    $byResource = collect($summary['groups']['resource'])->keyBy('label');
    expect($byResource['VEH-01 · Land Cruiser'])->toMatchArray(['entries' => 2, 'km' => 300.0, 'estimated_l' => 30.0, 'actual_l' => 12.0, 'variance_l' => 2.0])
        ->and($byResource['VEH-02 · Pickup'])->toMatchArray(['entries' => 1, 'variance_l' => -1.0]);
    expect(collect($summary['groups']['operator'])->pluck('entries', 'label')->all())->toBe([$this->team['writer']->name => 2, $this->team['other']->name => 1]);
    expect(collect($summary['groups']['shift'])->pluck('entries', 'label')->all())->toEqual(['No shift' => 2, 'Day · 06:00–18:00' => 1]);
    expect(collect($summary['groups']['month'])->pluck('entries', 'key')->all())->toBe(['2026-10' => 1, '2026-11' => 2]);
});

it('counts a corrected activity once, with the figure of its last submission', function () {
    as_($this->team['writer'], 'logbook_contributor');
    $entry = recordTrip($this->v1, '1000', '1100');
    as_($this->team['reviewer'], 'logbook_reviewer');
    $this->post(p("entries/{$entry->id}/review"), ['decision' => 'returned', 'comment' => 'Odometer in was 1150', 'revision' => 1])->assertSessionHasNoErrors();
    as_($this->team['writer'], 'logbook_contributor');
    $this->put(p("entries/{$entry->id}"), ['occurred_at' => '2026-11-20T08:00', 'activity_started_at' => '2026-11-20T08:00', 'activity_ended_at' => '2026-11-20T17:00',
        'values' => ['unit' => $this->v1->id, 'start' => '1000', 'end' => '1150'], 'submit' => true])->assertSessionHasNoErrors();

    as_($this->team['manager'], 'logbook_manager');
    $summary = $this->get(p('reports?view=activities'))->viewData('page')['props']['activities']['summary'];
    expect($summary['total'])->toMatchArray(['entries' => 1, 'km' => 150.0, 'estimated_l' => 15.0]);
});

it('keeps the totals on the rates the entries were recorded with', function () {
    as_($this->team['writer'], 'logbook_contributor');
    recordTrip($this->v1, '1000', '1100');
    as_($this->team['manager'], 'logbook_manager');
    $this->post(p("resources/{$this->v1->id}/fuel-rates"), ['fuel_type' => 'diesel', 'method' => 'distance', 'rate' => '30', 'effective_from' => '2026-01-01T00:00'])->assertSessionHasNoErrors();
    $summary = $this->get(p('reports?view=activities'))->viewData('page')['props']['activities']['summary'];
    expect($summary['total']['estimated_l'])->toEqual(10.0);
});

it('filters by how sheets are recorded, including sheets from before recording modes', function () {
    $notes = publishedSheet($this->logbook, 'OBS', review: false);
    $legacy = publishedSheet($this->logbook, 'GEN', review: false);
    // A schedule from before recording modes makes GEN a scheduled sheet.
    inTenant(fn () => Schedule::query()->create([
        'logbook_id' => $this->logbook->id, 'log_sheet_id' => $legacy->id, 'frequency' => 'daily', 'opens_time' => '07:00', 'due_time' => '09:00',
        'late_minutes' => 60, 'timezone' => 'UTC', 'starts_on' => '2026-11-20', 'completion' => 'submitted', 'created_by' => $this->team['manager']->id,
    ]));
    as_($this->team['writer'], 'logbook_contributor');
    recordTrip($this->v1, '1000', '1100');
    writeEntry($notes)->assertSessionHasNoErrors();
    writeEntry($legacy)->assertSessionHasNoErrors();

    as_($this->team['manager'], 'logbook_manager');
    $count = fn (string $mode) => $this->get(p("reports?view=table&mode={$mode}"))->viewData('page')['props']['counts']['total'];
    expect([$count('event'), $count('on_demand'), $count('scheduled')])->toBe([1, 1, 1]);
    $this->get(p('reports?mode=weekly'))->assertSessionHasErrors('mode');
});

it('shows the activities, opens the calculation in the record panel, and exports the same figures', function () {
    as_($this->team['writer'], 'logbook_contributor');
    $a = recordTrip($this->v1, '1000', '1100', ['values' => ['used' => '12', 'refill' => '50']]);
    recordTrip($this->v2, '500', '550');

    as_($this->team['manager'], 'logbook_manager');
    $this->get(p("reports?view=activities&resource={$this->v1->id}"))->assertInertia(fn ($page) => $page
        ->where('activities.rows.total', 1)
        ->where('activities.rows.data.0', fn ($row) => $row['reference'] === $a->reference && $row['resource'] === 'VEH-01 · Land Cruiser'
            && $row['estimatedL'] == 10 && $row['actualL'] == 12 && $row['varianceL'] == 2 && $row['refillL'] == 50 && $row['durationH'] == 9));

    $this->get(p("reports/records/{$a->id}"), ['X-InertiaUI-Modal' => (string) Str::uuid(), 'X-Inertia' => 'true'])
        ->assertOk()->assertJsonPath('props.entry.calculation.estimated_l', 10)->assertJsonPath('props.entry.calculation.resource', 'VEH-01 · Land Cruiser');

    $csv = $this->get(p('reports/activities.csv'))->assertOk()->streamedContent();
    $lines = array_map('str_getcsv', array_filter(explode("\n", trim(substr($csv, 3)))));
    expect($lines[0][13])->toBe('Estimated (L)')->and(count($lines))->toBe(3)
        ->and(array_sum(array_map(fn ($l) => (float) $l[13], array_slice($lines, 1))))->toEqual(15.0);
});

it('never shows another workspace\'s activities', function () {
    as_($this->team['writer'], 'logbook_contributor');
    recordTrip($this->v1, '1000', '1100');
    team('globex');
    as_(inTenant(fn () => User::query()->first(), 'globex'), 'logbook_manager', 'globex');
    $this->get(p('reports?view=activities', 'globex'))->assertInertia(fn ($page) => $page->where('activities.summary.count', 0)->where('activities.rows.total', 0));
    expect(trim(substr($this->get(p('reports/activities.csv', 'globex'))->streamedContent(), 3)))->not->toContain('VEH-01');
});
