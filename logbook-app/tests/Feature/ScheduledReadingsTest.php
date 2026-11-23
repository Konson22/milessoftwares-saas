<?php

use App\Logbook\Models\Entry;
use App\Logbook\Models\Schedule;
use App\Logbook\Models\ScheduleOccurrence;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/*
 * ADR-0030 phase 2: several readings a day on one schedule, windows past
 * midnight, retries that never double, and entries that say which
 * reading they are for (or that they are extra). Entries from clients
 * that say nothing keep the earlier match by time.
 */

beforeEach(function () {
    $this->travelTo('2026-11-20 00:05:00');
    $this->team = team();
    as_($this->team['manager'], 'logbook_manager');
    $this->logbook = logbook('Equipment operations');
    $this->sheet = publishedSheet($this->logbook, 'GOP', review: false);
});

/** Generator readings at 06, 12, 18 and midnight; the midnight one opens 23:30 and is due 00:30. */
function fourReadings(array $extra = []): Schedule
{
    test()->post(p('sheets/'.test()->sheet->id.'/schedules'), [
        'frequency' => 'daily', 'late_minutes' => 60, 'timezone' => 'UTC', 'starts_on' => '2026-11-20', 'completion' => 'submitted',
        'times' => [
            ['opens' => '11:30', 'due' => '12:30'],
            ['opens' => '05:30', 'due' => '06:30'],
            ['opens' => '23:30', 'due' => '00:30'],
            ['opens' => '17:30', 'due' => '18:30'],
        ],
        ...$extra,
    ])->assertSessionHasNoErrors();

    return inTenant(fn () => Schedule::query()->latest('created_at')->firstOrFail());
}

function readingEntry(string $at, array $extra = []): Entry
{
    as_(test()->team['writer'], 'logbook_contributor');
    test()->post(p('entries'), [
        'sheet_id' => test()->sheet->id, 'occurred_at' => $at, 'values' => generatorValues(),
        'idempotency_key' => (string) Str::uuid7(), 'submit' => true, ...$extra,
    ])->assertSessionHasNoErrors();

    return latestEntry();
}

function readingsOf(Schedule $schedule): Collection
{
    return inTenant(fn () => ScheduleOccurrence::query()->where('schedule_id', $schedule->id)->orderBy('opens_at')->get());
}

it('expects each reading of the day on its own, sorted, and never twice on a retry', function () {
    $schedule = fourReadings();
    expect($schedule->times)->toBe([
        ['opens' => '05:30', 'due' => '06:30'], ['opens' => '11:30', 'due' => '12:30'],
        ['opens' => '17:30', 'due' => '18:30'], ['opens' => '23:30', 'due' => '00:30'],
    ])->and([$schedule->opens_time, $schedule->due_time])->toBe(['05:30', '06:30']);

    $this->artisan('logbook:operations')->assertSuccessful();
    $this->artisan('logbook:operations')->assertSuccessful();
    $today = readingsOf($schedule)->filter(fn ($o) => $o->occurs_on->toDateString() === '2026-11-20');
    expect($today->pluck('slot')->all())->toBe([0, 1, 2, 3])
        ->and($today->map(fn ($o) => $o->due_at->format('Y-m-d H:i'))->values()->all())
        ->toBe(['2026-11-20 06:30', '2026-11-20 12:30', '2026-11-20 18:30', '2026-11-21 00:30']);

    // The database refuses a second copy of the same reading.
    $first = $today->first();
    expect(fn () => inTenant(fn () => ScheduleOccurrence::query()->create([
        ...$first->only(['schedule_id', 'logbook_id', 'log_sheet_id', 'slot', 'opens_at', 'due_at', 'closes_at']),
        'occurs_on' => '2026-11-20', 'status' => 'open',
    ])))->toThrow(UniqueConstraintViolationException::class);
});

it('counts a reading taken after midnight for the window that opened before it', function () {
    $schedule = fourReadings();
    $this->travelTo('2026-11-20 23:00:00');
    $this->artisan('logbook:operations')->assertSuccessful();
    $midnight = readingsOf($schedule)->first(fn ($o) => $o->occurs_on->toDateString() === '2026-11-20' && $o->slot === 3);

    $this->travelTo('2026-11-21 00:20:00');
    $entry = readingEntry('2026-11-21T00:10', ['schedule_link' => 'named', 'schedule_occurrence_id' => $midnight->id]);
    $fresh = inTenant(fn () => $midnight->fresh());
    expect($fresh->log_entry_id)->toBe($entry->id)->and($fresh->status)->toBe('completed')->and($fresh->timeliness)->toBe('on_time');
});

it('keeps an extra reading apart from the expected ones', function () {
    $schedule = fourReadings();
    $this->travelTo('2026-11-20 06:00:00');
    $this->artisan('logbook:operations')->assertSuccessful();

    // 06:10 falls in the 05:30–07:30 window, but the operator says it is an extra observation.
    $extra = readingEntry('2026-11-20T06:10', ['schedule_link' => 'extra']);
    $morning = readingsOf($schedule)->firstWhere('slot', 0);
    expect(inTenant(fn () => $morning->fresh()->log_entry_id))->toBeNull()
        ->and($extra->schedule_link)->toBe('extra')->and($extra->schedule_occurrence_id)->toBeNull();

    // The proper reading, named, completes it.
    $reading = readingEntry('2026-11-20T06:15', ['schedule_link' => 'named', 'schedule_occurrence_id' => $morning->id]);
    expect(inTenant(fn () => $morning->fresh()->log_entry_id))->toBe($reading->id);
});

it('does not move a named entry that no longer fits to another reading', function () {
    $schedule = fourReadings();
    $this->travelTo('2026-11-20 12:00:00');
    $this->artisan('logbook:operations')->assertSuccessful();
    [$morning, $noon] = [readingsOf($schedule)->firstWhere('slot', 0), readingsOf($schedule)->firstWhere('slot', 1)];

    // Names the morning reading but was taken at noon: outside that window, so it counts for neither.
    $entry = readingEntry('2026-11-20T12:00', ['schedule_link' => 'named', 'schedule_occurrence_id' => $morning->id]);
    expect(inTenant(fn () => [$morning->fresh()->log_entry_id, $noon->fresh()->log_entry_id]))->toBe([null, null]);
    expect($entry->status)->toBe('finalized');
});

it('still matches entries from clients that do not say, by their time', function () {
    $schedule = fourReadings();
    $this->travelTo('2026-11-20 12:00:00');
    $this->artisan('logbook:operations')->assertSuccessful();
    $noon = readingsOf($schedule)->firstWhere('slot', 1);

    // An older phone build: no schedule_link, no occurrence.
    $entry = readingEntry('2026-11-20T12:05');
    expect($entry->schedule_link)->toBeNull()->and(inTenant(fn () => $noon->fresh()->log_entry_id))->toBe($entry->id);
});

it('only lets entries that did not say count for a reading made after them', function () {
    // Written before the schedule ran (offline), one extra and one unsaid.
    $this->travelTo('2026-11-20 06:00:00');
    $schedule = fourReadings();
    $extra = readingEntry('2026-11-20T06:05', ['schedule_link' => 'extra']);
    $unsaid = readingEntry('2026-11-20T06:10');
    as_($this->team['manager'], 'logbook_manager');
    $this->artisan('logbook:operations')->assertSuccessful();

    $morning = readingsOf($schedule)->firstWhere('slot', 0);
    expect(inTenant(fn () => $morning->fresh()->log_entry_id))->toBe($unsaid->id)->not->toBe($extra->id);
});

it('refuses readings that overlap, repeat a time, or are not times', function () {
    $post = fn (array $times) => $this->post(p("sheets/{$this->sheet->id}/schedules"), [
        'frequency' => 'daily', 'late_minutes' => 60, 'timezone' => 'UTC', 'starts_on' => '2026-11-20', 'completion' => 'submitted', 'times' => $times,
    ]);
    $post([['opens' => '05:00', 'due' => '07:00'], ['opens' => '06:30', 'due' => '08:00']])->assertSessionHasErrors('times');
    // The overnight one runs into the first reading of the next day.
    $post([['opens' => '05:00', 'due' => '06:00'], ['opens' => '23:00', 'due' => '05:30']])->assertSessionHasErrors('times');
    $post([['opens' => '06:00', 'due' => '06:00']])->assertSessionHasErrors();
    $post([['opens' => '6am', 'due' => '07:00']])->assertSessionHasErrors();
    $post(array_map(fn ($h) => ['opens' => sprintf('%02d:00', $h), 'due' => sprintf('%02d:30', $h)], range(0, 23)))->assertSessionHasNoErrors();
    expect(inTenant(fn () => Schedule::query()->count()))->toBe(1);
});

it('keeps one-time schedules from older forms working', function () {
    $this->post(p("sheets/{$this->sheet->id}/schedules"), [
        'frequency' => 'daily', 'opens_time' => '07:00', 'due_time' => '09:00', 'late_minutes' => 60,
        'timezone' => 'UTC', 'starts_on' => '2026-11-20', 'completion' => 'submitted',
    ])->assertSessionHasNoErrors();
    $schedule = inTenant(fn () => Schedule::query()->sole());
    expect($schedule->times)->toBe([['opens' => '07:00', 'due' => '09:00']]);
    $this->artisan('logbook:operations')->assertSuccessful();
    expect(readingsOf($schedule)->pluck('slot')->unique()->all())->toBe([0]);
});

it('works in the workspace time zone', function () {
    $this->post(p("sheets/{$this->sheet->id}/schedules"), [
        'frequency' => 'daily', 'late_minutes' => 60, 'timezone' => 'Africa/Juba', 'starts_on' => '2026-11-20', 'completion' => 'submitted',
        'times' => [['opens' => '05:30', 'due' => '06:30'], ['opens' => '23:30', 'due' => '00:30']],
    ])->assertSessionHasNoErrors();
    $schedule = inTenant(fn () => Schedule::query()->sole());
    $this->artisan('logbook:operations')->assertSuccessful();
    // Juba is UTC+2: 06:30 there is 04:30 UTC; the midnight reading is due 22:30 UTC the same day.
    $made = readingsOf($schedule)->filter(fn ($o) => $o->occurs_on->toDateString() === '2026-11-20');
    expect($made->map(fn ($o) => $o->due_at->utc()->format('Y-m-d H:i'))->values()->all())->toBe(['2026-11-20 04:30', '2026-11-20 22:30']);
});

it('offers the open readings, or an extra one, on the entry form', function () {
    $schedule = fourReadings();
    $this->travelTo('2026-11-20 12:00:00');
    $this->artisan('logbook:operations')->assertSuccessful();
    as_($this->team['writer'], 'logbook_contributor');
    $this->get(p("entries/new?sheet={$this->sheet->id}"))->assertInertia(fn ($page) => $page
        ->where('scheduled', true)
        ->where('readings', fn ($r) => collect($r)->pluck('id')->contains(readingsOf($schedule)->firstWhere('slot', 1)->id)));

    // An on-demand sheet shows no readings.
    as_($this->team['manager'], 'logbook_manager');
    $notes = publishedSheet($this->logbook, 'OBS', review: false);
    as_($this->team['writer'], 'logbook_contributor');
    $this->get(p("entries/new?sheet={$notes->id}"))->assertInertia(fn ($page) => $page->where('scheduled', false)->where('readings', []));
});
